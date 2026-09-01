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

    private function paymentMethod(string $factoryName = 'simplepay'): PaymentMethodInterface
    {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
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
     */
    private function knownPayment(): PaymentInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn(self::ORDER_NUMBER);

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

    /** @param list<object> $executed */
    private function controller(
        array &$executed,
        ?PaymentMethodInterface $paymentMethod,
        ?PaymentInterface $payment,
        ?\Throwable $resolveThrows = null,
        ?EntityManagerInterface $entityManager = null,
        string $code = 'simplepay',
        string $orderRef = 'EZ-2026-0042-17-1',
    ): IpnController {
        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['code' => $code])
            ->willReturn($paymentMethod);

        $paymentRepository = $this->createStub(PaymentRepositoryInterface::class);
        $paymentRepository->method('find')->willReturn($payment);

        $payum = $this->createStub(Payum::class);
        $payum->method('getGateway')->willReturn($this->gateway($executed, $resolveThrows, $orderRef));

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

    public function testItResolvesBeforeItLooksUpThePaymentSoItNeverTrustsAnUnverifiedBody(): void
    {
        $executed = [];

        $this->controller($executed, $this->paymentMethod(), $this->knownPayment())(
            $this->request(),
            'simplepay',
        );

        self::assertInstanceOf(ResolveSimplePayIpn::class, $executed[0]);
        self::assertInstanceOf(Notify::class, $executed[1]);
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
