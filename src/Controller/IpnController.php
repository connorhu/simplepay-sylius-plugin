<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Controller;

use CodeConjure\SimplePay\Exception\SimplePayException;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Bundle\PayumBundle\ReplyToSymfonyResponseConverter;
use Payum\Core\Payum;
use Payum\Core\Reply\ReplyInterface;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A SimplePay értesítéseinek (IPN) fogadása.
 *
 * A SimplePay EGYETLEN, a kereskedői vezérlőpanelen beállított URL-re
 * posztol, token nélkül — per-kérésben nincs IPN-cím mező. A payment method
 * kódja ezért az ÚTVONALBAN van: ezzel eltűnik a „melyik merchanthez tartozik
 * ez az üzenet?" tojás-tyúk kérdés, és nem kell az aláíratlan törzsből
 * olvasnunk semmit ahhoz, hogy megtaláljuk a hitelesítéshez szükséges titkot.
 *
 * A feldolgozás sorrendje szándékos:
 *   1. payment method a kódból (útvonal, nem törzs)
 *   2. ResolveSimplePayIpn — ellenőriz és parse-ol, modell nélkül
 *   3. Payment keresés a MOST MÁR HITELESÍTETT orderRef alapján
 *   4. Notify — állapotgép és aláírt visszaigazolás
 */
final class IpnController
{
    /**
     * A hitelesítetlen ágakon (aláírás hiányzik, vagy nem stimmel) naplózott
     * törzs-részlet felső korlátja bájtban. A végpont hitelesítés nélkül
     * hívható, tehát ezeken az ágakon a törzs TÁMADÓ ÁLTAL VEZÉRELT — ha
     * korlátlanul naplóznánk, egy ismételt POST-ozással bárki megtölthetné a
     * lemezt, aki ismeri az URL-t. Egy valódi SimplePay IPN törzs jellemzően
     * néhány száz bájt (orderRef, transactionId, status és néhány dátum); a
     * 4096 bájt (4 KiB) ennek több, mint tízszerese, tehát bőven elég a
     * hibakereséshez, ugyanakkor nagyságrendekkel kisebb annál, hogy egy
     * visszatérő kérés érdemben árasztani tudná vele a naplófájlt.
     */
    private const int UNTRUSTED_BODY_EXCERPT_LIMIT = 4096;

    private readonly LoggerInterface $logger;

    /**
     * A `logger` a `services.xml`-ben `on-invalid="null"`, tehát a konténer
     * `null`-t ad, ha nincs naplózó. A paraméternek ezért nullable-nek KELL
     * lennie: a Payum-csomagban pontosan ez a párosítás — `on-invalid="null"`
     * ígéret és nem-nullable konstruktor — akadályozta meg a bundle bootolását,
     * és csak a konténer-fordítási teszt találta meg.
     *
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param PaymentRepositoryInterface<PaymentInterface>             $paymentRepository
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReplyToSymfonyResponseConverter $replyConverter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(Request $request, string $code): Response
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);

        if (!$paymentMethod instanceof PaymentMethodInterface || !GatewayConfigReader::isSimplePay($paymentMethod)) {
            throw new NotFoundHttpException(sprintf(
                'Nincs "%s" kódú SimplePay fizetési mód.',
                $code,
            ));
        }

        $signature = (string) $request->headers->get('Signature', '');

        if ('' === trim($signature)) {
            $this->logger->error('SimplePay értesítés érkezett Signature fejléc nélkül.', [
                'event' => 'simplepay.ipn.unsigned',
                'payment_method' => $code,
                'client_ip' => $request->getClientIp(),
                ...$this->untrustedBodyContext($request->getContent()),
            ]);

            return new Response('Missing Signature header.', Response::HTTP_BAD_REQUEST);
        }

        // A `$code` (ami a route paramétere, tehát a repository-keresésre
        // helyes kulcs) NEM feltétlenül a Payum gateway neve — lásd
        // `GatewayConfigReader::gatewayName()` docblockját. Ugyanezt a
        // felolvasást a `RefundCommand` is használja — R27 szünteti meg a
        // korábbi duplikációt.
        $gatewayName = GatewayConfigReader::gatewayName($paymentMethod);

        $gateway = $this->payum->getGateway($gatewayName);

        try {
            $gateway->execute($resolve = new ResolveSimplePayIpn($request->getContent(), $signature));
        } catch (SimplePayException $exception) {
            // Hamis aláírás vagy idegen merchant. SOSEM újrapróbálandó, és
            // visszaigazolás sem jár érte — ha válaszolnánk, egy támadó
            // aláírás nélkül is le tudná állítani a valódi értesítéseket.
            $this->logger->error('SimplePay értesítés hitelesítése sikertelen.', [
                'event' => 'simplepay.ipn.rejected',
                'payment_method' => $code,
                'client_ip' => $request->getClientIp(),
                'reason' => $exception->getMessage(),
                ...$this->untrustedBodyContext($request->getContent()),
            ]);

            return new Response('Invalid notification.', Response::HTTP_BAD_REQUEST);
        }

        $message = $resolve->getMessage();
        $payment = $this->findPayment($message->orderRef, $request->getContent());

        if (!$payment instanceof PaymentInterface) {
            // A törzs itt már ÁTMENT az aláírás-ellenőrzésen — hiteles, tehát
            // korlátozás nélkül naplózható. Ez az az eset, ahol egy teljes
            // törzs a leghasznosabb: nincs `Payment`, amihez a hibát
            // köthetnénk, tehát a strukturált mezők (`order_ref`,
            // `transaction_id`) önmagukban nem mondják meg, mit is küldött
            // pontosan a SimplePay.
            $this->logger->error('SimplePay értesítés érkezett ismeretlen rendelésre.', [
                'event' => 'simplepay.ipn.unknown_order',
                'order_ref' => $message->orderRef,
                'transaction_id' => $message->transactionId,
                'body' => $request->getContent(),
            ]);
        }

        // Ismeretlen rendelésnél is aláírt visszaigazolás megy: a SimplePay
        // különben örökké ismételne egy olyan üzenetet, amivel sosem fogunk
        // tudni mit kezdeni. A modell ilyenkor egy eldobható ArrayObject —
        // a NotifyAction előállítja a visszaigazolást, csak nincs hová
        // írnia az állapotot.
        $model = $payment ?? new \ArrayObject([]);

        try {
            $gateway->execute(new Notify($model));
        } catch (ReplyInterface $reply) {
            if ($payment instanceof PaymentInterface) {
                $this->entityManager->flush();
            }

            return $this->replyConverter->convert($reply);
        }

        // Ide nem szabadna eljutni: a NotifyAction mindig reply-t dob.
        //
        // A törzs itt SZÁNDÉKOSAN nem kerül a naplóba. Ha idáig eljutottunk,
        // a `ResolveSimplePayIpn` már sikeresen hitelesítette és feldolgozta
        // az üzenetet, és a `Notify` is lefutott anélkül, hogy elakadt volna
        // — az anomália nem a törzs TARTALMÁVAL van (azt a `$message` már
        // teljesen leírja), hanem a NotifyAction (fagyasztott, 2. réteg)
        // viselkedésével. Ez pontosan az a helyzet, amit a feladat a
        // sikeres ágra ír elő: a strukturált mezők önmagukban elmondják a
        // lényeget, a törzs újbóli naplózása itt zaj lenne.
        $this->logger->error('A SimplePay NotifyAction nem adott visszaigazolást.', [
            'event' => 'simplepay.ipn.no_confirmation',
            'order_ref' => $message->orderRef,
        ]);

        return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function findPayment(string $orderRef, string $body): ?PaymentInterface
    {
        $reference = OrderReference::tryParse($orderRef);

        if (null === $reference) {
            return null;
        }

        $payment = $this->paymentRepository->find($reference->paymentId);

        if (!$payment instanceof PaymentInterface) {
            return null;
        }

        // Keresztellenőrzés: a hivatkozásban lévő rendelésszámnak egyeznie
        // kell a megtalált fizetés rendelésével. Enélkül egy elgépelt vagy
        // átfedő azonosító idegen rendelést módosíthatna.
        if ($payment->getOrder()?->getNumber() !== $reference->orderNumber) {
            // A törzs itt is hiteles — ugyanúgy, mint az `unknown_order` ágon
            // — hiszen ide csak sikeres aláírás-ellenőrzés UTÁN jutunk el.
            // Korlátozás nélkül naplózzuk: a teljes törzs olyan mezőket is
            // tartalmaz (pl. `transactionId`, `merchant`), amiket a fenti
            // `order_ref` és `payment_id` önmagában nem ad vissza, pedig épp
            // egy azonosító-eltérés kivizsgálásához kellenek.
            $this->logger->error('A SimplePay hivatkozás rendelésszáma nem egyezik a megtalált fizetésével.', [
                'event' => 'simplepay.ipn.order_mismatch',
                'order_ref' => $orderRef,
                'payment_id' => $reference->paymentId,
                'body' => $body,
            ]);

            return null;
        }

        return $payment;
    }

    /**
     * Naplózható kontextus egy MÉG NEM hitelesített (aláírás nélküli vagy
     * hamis aláírású) törzshöz: a `body_excerpt` a `UNTRUSTED_BODY_EXCERPT_LIMIT`
     * bájtra vágott törzs, a `body_length` pedig a törzs VALÓDI, vágás
     * előtti hossza — enélkül egy csonkolt bejegyzés hazudna arról, mekkora
     * volt az eredeti kérés.
     *
     * @return array{body_excerpt: string, body_length: int}
     */
    private function untrustedBodyContext(string $body): array
    {
        return [
            'body_excerpt' => substr($body, 0, self::UNTRUSTED_BODY_EXCERPT_LIMIT),
            'body_length' => strlen($body),
        ];
    }
}
