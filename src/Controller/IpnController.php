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
            ]);

            return new Response('Invalid notification.', Response::HTTP_BAD_REQUEST);
        }

        $message = $resolve->getMessage();
        $payment = $this->findPayment($message->orderRef);

        if (!$payment instanceof PaymentInterface) {
            $this->logger->error('SimplePay értesítés érkezett ismeretlen rendelésre.', [
                'event' => 'simplepay.ipn.unknown_order',
                'order_ref' => $message->orderRef,
                'transaction_id' => $message->transactionId,
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
        $this->logger->error('A SimplePay NotifyAction nem adott visszaigazolást.', [
            'event' => 'simplepay.ipn.no_confirmation',
            'order_ref' => $message->orderRef,
        ]);

        return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function findPayment(string $orderRef): ?PaymentInterface
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
            $this->logger->error('A SimplePay hivatkozás rendelésszáma nem egyezik a megtalált fizetésével.', [
                'event' => 'simplepay.ipn.order_mismatch',
                'order_ref' => $orderRef,
                'payment_id' => $reference->paymentId,
            ]);

            return null;
        }

        return $payment;
    }
}
