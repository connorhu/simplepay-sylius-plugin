<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Controller;

use CodeConjure\SimplePay\Exception\SignatureException;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Bundle\PayumBundle\ReplyToSymfonyResponseConverter;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IpnControllerTest extends TestCase
{
    private const string BODY = '{"orderRef":"EZ-2026-0042-17-1","status":"FINISHED"}';

    private const string CONFIRMATION = '{"orderRef":"EZ-2026-0042-17-1","status":"FINISHED","receiveDate":"…"}';

    private const string ORDER_NUMBER = 'EZ-2026-0042';

    /**
     * A `$gatewayName` a Payum regiszterbeli kulcs — a Sylius ezt a fizetési
     * mód kódjából GENERÁLJA (kisbetűsítve, aláhúzással), tehát a valóságban
     * csak akkor egyezik a kóddal, ha a kód már eleve kisbetűs és aláhúzásos.
     * Alapértéke a jelenlegi tesztek meglévő 'simplepay' kódjával egyezik meg
     * — ez a véletlen egyezés az oka, hogy a `$code`-dal való gateway-keresés
     * hibája korábban egyetlen tesztet sem buktatott meg.
     */
    private function paymentMethod(string $factoryName = 'simplepay', string $gatewayName = 'simplepay'): PaymentMethodInterface
    {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getGatewayName')->willReturn($gatewayName);
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        return $method;
    }

    /**
     * A `Notify` sikeres feldolgozásához a `findPayment()` keresztellenőrzésének
     * illeszkednie kell: a talált `Payment` rendelésszáma egyezzen a
     * `BODY`-ban lévő `orderRef` rendelésszám-részével (lásd `ORDER_NUMBER`).
     * E nélkül a mock `Payment` `getOrder()`-je `null`-t adna, a
     * keresztellenőrzés elutasítaná, és a fizetés csendben az „ismeretlen
     * rendelés" ágra esne — pont azt az esetet nem tesztelnénk, aminek a
     * nevében szerepel.
     *
     * Az `$orderNumber` paraméterezhető: a keresztellenőrzés ELUTASÍTÓ
     * ágának teszteléséhez szándékosan EL KELL térnie a `BODY` orderRef-jének
     * rendelésszám-részétől.
     */
    private function knownPayment(string $orderNumber = self::ORDER_NUMBER): PaymentInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn($orderNumber);

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }

    /**
     * @param list<object> $executed a gateway által látott requestek
     */
    private function gateway(
        array &$executed,
        ?\Throwable $resolveThrows = null,
        string $orderRef = 'EZ-2026-0042-17-1',
    ): GatewayInterface {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use (&$executed, $resolveThrows, $orderRef): void {
                $executed[] = $request;

                if ($request instanceof ResolveSimplePayIpn) {
                    if (null !== $resolveThrows) {
                        throw $resolveThrows;
                    }

                    $message = new \CodeConjure\SimplePay\Ipn\IpnMessage(
                        merchant: 'PUBLICTESTHUF',
                        orderRef: $orderRef,
                        transactionId: '99844942',
                        status: \CodeConjure\SimplePay\TransactionStatus::Finished,
                    );

                    $request->setMessage($message);

                    return;
                }

                if ($request instanceof Notify) {
                    throw new HttpResponse(self::CONFIRMATION, 200, ['Signature' => 'aláírás']);
                }
            },
        );

        return $gateway;
    }

    /**
     * @param list<object> $executed
     *
     * A `$trackPaymentLookup=true` a `paymentRepository->find()` hívást is
     * naplózza az `$executed`-be egy `PaymentLookupProbe`-ként — ez teszi
     * mérhetővé, hogy a keresés a resolve ELŐTT vagy UTÁN történt-e.
     * Alapból kikapcsolva marad, hogy a többi teszt `$executed` naplója
     * ne változzon: azoknak csak a gateway-hívások sorrendje számít.
     */
    private function controller(
        array &$executed,
        ?PaymentMethodInterface $paymentMethod,
        ?PaymentInterface $payment,
        ?\Throwable $resolveThrows = null,
        ?EntityManagerInterface $entityManager = null,
        string $code = 'simplepay',
        string $orderRef = 'EZ-2026-0042-17-1',
        bool $trackPaymentLookup = false,
        ?string $expectedGatewayName = null,
    ): IpnController {
        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['code' => $code])
            ->willReturn($paymentMethod);

        $paymentRepository = $this->createStub(PaymentRepositoryInterface::class);

        if ($trackPaymentLookup) {
            $paymentRepository->method('find')->willReturnCallback(
                static function (mixed $id) use (&$executed, $payment): ?PaymentInterface {
                    $executed[] = new PaymentLookupProbe($id);

                    return $payment;
                },
            );
        } else {
            $paymentRepository->method('find')->willReturn($payment);
        }

        // `$expectedGatewayName` NEM `null` esetén a Payum double `createMock()`,
        // és az argumentumot is ellenőrzi — ez az egyetlen mód rá, hogy egy
        // teszt buktassa, ha a `getGateway()` a payment method KÓDJÁT kapná a
        // hitelesített `gatewayName` helyett.
        if (null !== $expectedGatewayName) {
            $payum = $this->createMock(Payum::class);
            $payum->expects(self::once())
                ->method('getGateway')
                ->with($expectedGatewayName)
                ->willReturn($this->gateway($executed, $resolveThrows, $orderRef));
        } else {
            $payum = $this->createStub(Payum::class);
            $payum->method('getGateway')->willReturn($this->gateway($executed, $resolveThrows, $orderRef));
        }

        return new IpnController(
            $paymentMethodRepository,
            $paymentRepository,
            $payum,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new ReplyToSymfonyResponseConverter(),
            new NullLogger(),
        );
    }

    private function request(): Request
    {
        return Request::create(
            '/payment/simplepay/ipn/simplepay',
            'POST',
            server: ['HTTP_SIGNATURE' => 'aláírás'],
            content: self::BODY,
        );
    }

    public function testAnAuthenticatedNotificationIsAnsweredWithTheSignedConfirmation(): void
    {
        $executed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            $this->knownPayment(),
            entityManager: $entityManager,
        )($this->request(), 'simplepay');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CONFIRMATION, $response->getContent());
        self::assertSame('aláírás', $response->headers->get('Signature'));
    }

    public function testTheGatewayIsResolvedByTheGatewayNameNotByThePaymentMethodCode(): void
    {
        // A Payum regiszterben a gateway a `gatewayName` alatt fut, amit a
        // Sylius a fizetési mód KÓDJÁBÓL generál (kisbetűsítve, aláhúzással,
        // lásd `GatewayNameGenerator`) — ez a két érték csak akkor egyezik,
        // ha a kód már eleve kisbetűs és aláhúzásos. Ez a teszt szándékosan
        // olyan kódot használ ("SimplePay HU"), ami NEM egyezik a
        // gateway-nevével ("simplepay_hu"): ha a controller a `$code`-ot
        // adná a `Payum::getGateway()`-nek a hitelesített `gatewayName`
        // helyett, a mock `with()` elvárása buktatná ezt a tesztet.
        $executed = [];

        $response = $this->controller(
            $executed,
            $this->paymentMethod(gatewayName: 'simplepay_hu'),
            $this->knownPayment(),
            code: 'SimplePay HU',
            expectedGatewayName: 'simplepay_hu',
        )($this->request(), 'SimplePay HU');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testItResolvesBeforeItLooksUpThePaymentSoItNeverTrustsAnUnverifiedBody(): void
    {
        // A törzsben (a HTTP kérés tartalmában) egy MEGBÍZHATATLAN orderRef
        // szerepel — más paymentId-vel (9999), mint amit a resolve UTÁN a
        // hitelesített `IpnMessage` ad (17). Ha a controller a törzsből
        // olvasná ki az orderRef-et MÉG A RESOLVE ELŐTT, a payment repository
        // hívása vagy megelőzné a `ResolveSimplePayIpn`-t a naplóban, vagy
        // a 9999-es (hamis) azonosítóval történne — mindkettőt elkapja ez
        // a teszt.
        $executed = [];

        $spoofedRequest = Request::create(
            '/payment/simplepay/ipn/simplepay',
            'POST',
            server: ['HTTP_SIGNATURE' => 'aláírás'],
            content: '{"orderRef":"TAMADO-9999-1","status":"FINISHED"}',
        );

        $this->controller(
            $executed,
            $this->paymentMethod(),
            $this->knownPayment(),
            trackPaymentLookup: true,
        )($spoofedRequest, 'simplepay');

        self::assertCount(3, $executed);
        self::assertInstanceOf(ResolveSimplePayIpn::class, $executed[0]);
        self::assertInstanceOf(PaymentLookupProbe::class, $executed[1]);
        self::assertSame(
            17,
            $executed[1]->paymentId,
            'A keresésnek a HITELESÍTETT üzenet orderRef-jéből származó paymentId-t kell '
            . 'használnia, nem a törzsben lévőt.',
        );
        self::assertInstanceOf(Notify::class, $executed[2]);
    }

    public function testAnUnknownPaymentMethodCodeIsANotFound(): void
    {
        $executed = [];

        $this->expectException(NotFoundHttpException::class);

        $this->controller($executed, null, null, code: 'nincs-ilyen')($this->request(), 'nincs-ilyen');
    }

    public function testAPaymentMethodOfAnotherGatewayIsANotFound(): void
    {
        $executed = [];

        $this->expectException(NotFoundHttpException::class);

        $this->controller($executed, $this->paymentMethod('offline'), null)($this->request(), 'simplepay');
    }

    public function testAForgedSignatureProducesA400AndNoConfirmation(): void
    {
        $executed = [];

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            null,
            new SignatureException('hamis aláírás'),
        )($this->request(), 'simplepay');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->headers->get('Signature', ''));
    }

    public function testAnUnknownOrderIsStillConfirmedSoSimplePayStopsRetrying(): void
    {
        // Ha egy nem létező rendelésre nem válaszolunk, a SimplePay örökké
        // ismételne. Visszaigazolunk, és error szinten naplózunk.
        //
        // NYITOTT DÖNTÉS (lásd a feladatleírást): mivel nincs `Payment`,
        // a `Notify` egy eldobható `\ArrayObject` modellel fut le — a
        // `NotifyAction` így is elő tudja állítani az aláírt választ, csak
        // nincs hová írnia az állapotot. Ezért a `Notify` request IS lefut
        // (nem csak a `ResolveSimplePayIpn`), és a modellje NEM a Sylius
        // `Payment`. A `flush()` viszont NEM fut — nincs mit perzisztálni.
        $executed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            null,
            entityManager: $entityManager,
        )($this->request(), 'simplepay');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', (string) $response->getContent());

        self::assertCount(2, $executed, 'Ismeretlen rendelésnél is lefut a Notify, eldobható modellel.');
        self::assertInstanceOf(Notify::class, $executed[1]);
        self::assertNotInstanceOf(
            PaymentInterface::class,
            $executed[1]->getModel(),
            'Ismeretlen rendelésnél a Notify modellje nem lehet a Sylius Payment.',
        );
    }

    public function testAMismatchedOrderNumberIsTreatedAsAnUnknownOrderSoAStrangersOrderCannotBeTouched(): void
    {
        // A `findPayment()` keresztellenőrzése: a hivatkozás rendelésszáma
        // egyezzen a megtalált `Payment` rendelésével. Ha a megtalált
        // `Payment` MÁSIK rendeléshez tartozik (elgépelt vagy átfedő
        // paymentId), a fizetést el kell utasítani — ez az ismeretlen
        // rendelés ágára esik: aláírt 200, de `flush()` nélkül, mert idegen
        // rendelést sosem szabad módosítani.
        $executed = [];
        $mismatchedPayment = $this->knownPayment('MASIK-RENDELES-0099');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            $mismatchedPayment,
            entityManager: $entityManager,
        )($this->request(), 'simplepay');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $executed, 'Rendelésszám-eltérésnél is lefut a Notify, eldobható modellel.');
        self::assertInstanceOf(Notify::class, $executed[1]);
        self::assertNotInstanceOf(
            PaymentInterface::class,
            $executed[1]->getModel(),
            'Rendelésszám-eltérésnél a Notify modellje nem lehet a Sylius Payment.',
        );
    }

    public function testAMalformedOrderReferenceIsStillConfirmedSoSimplePayStopsRetrying(): void
    {
        // Az `OrderReference::tryParse()` pontosan azért létezik, hogy egy
        // fel nem ismerhető formátumú `orderRef` ne kivétellel álljon meg,
        // hanem a dokumentált „ismeretlen rendelés" ágra fusson — ugyanúgy,
        // mint amikor a hivatkozás jólformált, de a `Payment` nincs meg.
        // Ha a controller a dobó `OrderReference::parse()`-t használná,
        // ez a teszt egy el nem kapott `InvalidArgumentException`-nel bukna.
        $executed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            null,
            entityManager: $entityManager,
            orderRef: 'ez-nem-illeszkedik-a-mintára',
        )($this->request(), 'simplepay');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $executed, 'Fel nem ismerhető hivatkozásnál is lefut a Notify, eldobható modellel.');
        self::assertInstanceOf(Notify::class, $executed[1]);
    }

    public function testAMissingSignatureHeaderProducesA400(): void
    {
        $executed = [];

        $request = Request::create(
            '/payment/simplepay/ipn/simplepay',
            'POST',
            content: self::BODY,
        );

        $response = $this->controller($executed, $this->paymentMethod(), null)($request, 'simplepay');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $executed, 'Aláírás nélkül a gateway-t meg sem szabad szólítani.');
    }
}
