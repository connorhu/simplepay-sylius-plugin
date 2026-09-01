<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Command;

use CodeConjure\SimplePay\Exception\TransportException;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RefundCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $details = [];

    private function payment(
        string $state = 'completed',
        ?int $amount = 100000,
        string $factoryName = 'simplepay',
        // A kód és a Payum-regiszterbeli gateway-név a valóságban SZÁNDÉKOSAN
        // eltér (ld. `GatewayNameGenerator`): a kódban lehet szóköz vagy
        // nagybetű, a gateway-név mindig kisbetűs, aláhúzásos. A két érték itt
        // tudatosan különbözik, hogy egy `getCode()`-ra visszaeső hiba
        // buktasson, ne csak véletlenül átmenjen.
        string $gatewayName = 'simplepay_gateway',
    ): PaymentInterface {
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
        $method->method('getCode')->willReturn('SimplePay HU');

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getState')->willReturn($state);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn('HUF');
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturnCallback(fn (): array => $this->details);
        $payment->method('setDetails')->willReturnCallback(
            /** @param array<string, mixed> $details */
            function (array $details): void {
                $this->details = $details;
            },
        );

        return $payment;
    }

    /**
     * @param list<object> $executed
     */
    private function tester(
        array &$executed,
        ?PaymentInterface $payment,
        ?string $orderNumber = 'EZ-2026-0042',
        ?EntityManagerInterface $entityManager = null,
        ?RecordingHttpClient $recordingHttpClient = null,
        ?\Throwable $refundThrows = null,
        ?string $expectGatewayName = null,
    ): CommandTester {
        $order = null;

        if (null !== $orderNumber) {
            $order = $this->createStub(OrderInterface::class);
            $order->method('getNumber')->willReturn($orderNumber);
            $order->method('getPayments')->willReturn(
                new ArrayCollection(null === $payment ? [] : [$payment]),
            );
        }

        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('findOneBy')->willReturn($order);

        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            function (object $request) use (&$executed, $refundThrows): void {
                $executed[] = $request;

                if (!$request instanceof Refund) {
                    return;
                }

                if (null !== $refundThrows) {
                    throw $refundThrows;
                }

                $state = $this->details[Details::STATE_KEY] ?? [];

                $this->details[Details::STATE_KEY] = array_merge(
                    is_array($state) ? $state : [],
                    ['refundTransactionId' => '509007601', 'refundTotal' => 500, 'remainingTotal' => 500],
                );
            },
        );

        if (null !== $expectGatewayName) {
            $payum = $this->createMock(Payum::class);
            $payum->expects(self::once())->method('getGateway')->with($expectGatewayName)->willReturn($gateway);
        } else {
            $payum = $this->createStub(Payum::class);
            $payum->method('getGateway')->willReturn($gateway);
        }

        $command = new RefundCommand(
            $orderRepository,
            $payum,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $recordingHttpClient ?? new RecordingHttpClient(
                $this->createStub(ClientInterface::class),
                sys_get_temp_dir(),
            ),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('simplepay:refund'));
    }

    /**
     * A `RecordingHttpClient` privát `enabled` mezőjét olvassa vissza —
     * a parancs ezen a példányon hívja az `enable()`-t, de a mezőnek
     * nincs publikus lekérdezője (ld. az osztály dokumentációját arról,
     * miért dekorátorként regisztráljuk, nem önálló szolgáltatásként).
     */
    private function isRecordingEnabled(RecordingHttpClient $client): bool
    {
        $property = new \ReflectionProperty(RecordingHttpClient::class, 'enabled');
        $value = $property->getValue($client);

        return is_bool($value) ? $value : throw new \LogicException(
            'A "enabled" mező váratlanul nem logikai típusú.',
        );
    }

    /**
     * A `simplepay_refund` névtér típusbiztos kiolvasása a teszt saját
     * `$details` állapotából. Enélkül a PHPStan a property utolsó
     * konkrét hozzárendelésének alakjához ragadna, és nem venné észre,
     * hogy a parancs futása közben a `setDetails()` callback bővíti.
     *
     * @return array<string, mixed>
     */
    private function refundDetails(): array
    {
        $refund = $this->details[Details::REFUND_KEY] ?? null;

        if (!is_array($refund)) {
            throw new \LogicException('Nincs "simplepay_refund" névtér a details-ben.');
        }

        $typed = [];

        foreach ($refund as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    public function testItRefundsTheFullAmountWhenNoneIsGiven(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = $this->tester($executed, $this->payment(), entityManager: $entityManager);
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(0, $exitCode);
        // 100000 Sylius-egység = 1000 Ft: a parancs számolja ki, nem az action.
        self::assertSame(1000, $this->refundDetails()['amount']);
        // Amit a kezelő a képernyőn lát elküldés ELŐTT, annak egyeznie kell
        // azzal, ami ténylegesen a details-be, majd a gateway-nek megy.
        self::assertStringContainsString('1000 HUF', $tester->getDisplay());
        self::assertCount(1, $executed);
        self::assertInstanceOf(Refund::class, $executed[0]);
    }

    public function testItRefundsTheGivenAmountConvertedToTheCurrencyMinorUnit(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = $this->tester($executed, $this->payment(), entityManager: $entityManager);
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042', '--amount' => '50000']);

        self::assertSame(0, $exitCode);
        self::assertSame(500, $this->refundDetails()['amount']);
        self::assertStringContainsString('500 HUF', $tester->getDisplay());
    }

    public function testItPrintsTheRefundResultSoTheOperatorSeesWhatHappened(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester($executed, $this->payment());
        $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        $output = $tester->getDisplay();

        self::assertStringContainsString('509007601', $output);
        self::assertStringContainsString('500', $output);
    }

    public function testAnUnknownOrderFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, null, orderNumber: null);
        $exitCode = $tester->execute(['orderNumber' => 'NINCS-ILYEN']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('NINCS-ILYEN', $tester->getDisplay());
        self::assertSame([], $executed);
    }

    public function testAnOrderWithoutASimplePayPaymentFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, null);
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertSame([], $executed);
    }

    public function testAPaymentOnAnotherGatewayFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, $this->payment(factoryName: 'offline'));
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertSame([], $executed);
    }

    public function testAPaymentThatIsNotCompletedFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, $this->payment(state: 'new'));
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('completed', $tester->getDisplay());
        self::assertSame([], $executed);
    }

    public function testAnUnrepresentableAmountFailsRatherThanRounding(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $tester = $this->tester($executed, $this->payment(), entityManager: $entityManager);
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042', '--amount' => '50050']);

        self::assertSame(1, $exitCode);
        self::assertSame([], $executed);
    }

    public function testAFailedGatewayCallReturnsFailureAndDoesNotFlush(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $tester = $this->tester(
            $executed,
            $this->payment(),
            entityManager: $entityManager,
            refundThrows: new TransportException('a SimplePay nem elérhető'),
        );
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('nem elérhető', $tester->getDisplay());
        self::assertCount(1, $executed);
    }

    public function testTheRecordOptionEnablesRawTrafficRecording(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $recordingHttpClient = new RecordingHttpClient(
            $this->createStub(ClientInterface::class),
            sys_get_temp_dir(),
        );

        $tester = $this->tester(
            $executed,
            $this->payment(),
            recordingHttpClient: $recordingHttpClient,
        );
        $tester->execute(['orderNumber' => 'EZ-2026-0042', '--record' => true]);

        self::assertTrue($this->isRecordingEnabled($recordingHttpClient));
    }

    public function testWithoutTheRecordOptionRecordingStaysDisabled(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $recordingHttpClient = new RecordingHttpClient(
            $this->createStub(ClientInterface::class),
            sys_get_temp_dir(),
        );

        $tester = $this->tester(
            $executed,
            $this->payment(),
            recordingHttpClient: $recordingHttpClient,
        );
        $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertFalse($this->isRecordingEnabled($recordingHttpClient));
    }

    /**
     * A Payum-regiszterben a gateway a `GatewayConfig::getGatewayName()`
     * alatt fut, amit a Sylius admin a fizetési mód KÓDJÁBÓL generál
     * (kisbetűsítve, aláhúzással) — ez nem feltétlenül egyezik a
     * `getCode()` nyers értékével. A teszt fixture-jében a kettő
     * szándékosan eltér ("SimplePay HU" vs. "simplepay_gateway"), hogy
     * egy `getCode()`-ra visszaeső hiba valódi hibaüzenettel bukjon.
     */
    public function testItLooksUpTheGatewayByItsRegisteredNameNotThePaymentMethodCode(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester(
            $executed,
            $this->payment(gatewayName: 'simplepay_gateway'),
            expectGatewayName: 'simplepay_gateway',
        );
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(0, $exitCode);
    }
}
