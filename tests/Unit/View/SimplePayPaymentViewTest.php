<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\View;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\View\SimplePayPaymentView;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class SimplePayPaymentViewTest extends TestCase
{
    /** @param array<string, mixed> $details */
    private function payment(array $details, string $factoryName = 'simplepay'): PaymentInterface
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

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);

        return $payment;
    }

    /** @return array<string, mixed> */
    private static function state(int $repeatCount = 1): array
    {
        return [
            Details::STATE_KEY => [
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'ipnLog' => [[
                    'receivedAt' => '2026-08-31T12:00:00+02:00',
                    'transactionId' => '99844942',
                    'status' => 'FINISHED',
                    'outcome' => 'applied',
                    'repeatCount' => $repeatCount,
                ]],
            ],
        ];
    }

    public function testItReadsTheStateOfASimplePayPayment(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment(self::state()));

        self::assertNotNull($view);
        self::assertSame('99844942', $view->transactionId);
        self::assertSame('FINISHED', $view->status);
        self::assertSame('sandbox', $view->environment);
        self::assertSame('2026-08-31T12:00:00+02:00', $view->lastIpnAt?->format(\DateTimeInterface::ATOM));
    }

    public function testLastIpnAtIsTheLastLogEntryNotTheFirst(): void
    {
        // Két bejegyzés, eltérő időbélyeggel — egy egyetlen bejegyzésből álló
        // napló nem különböztetné meg az "első" és az "utolsó" hibás
        // kiválasztását, ezért itt kettő kell.
        $details = [
            Details::STATE_KEY => [
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'ipnLog' => [
                    [
                        'receivedAt' => '2026-08-31T10:00:00+02:00',
                        'transactionId' => '99844942',
                        'status' => 'AUTHORIZED',
                        'outcome' => 'applied',
                        'repeatCount' => 1,
                    ],
                    [
                        'receivedAt' => '2026-08-31T12:00:00+02:00',
                        'transactionId' => '99844942',
                        'status' => 'FINISHED',
                        'outcome' => 'applied',
                        'repeatCount' => 1,
                    ],
                ],
            ],
        ];

        $view = SimplePayPaymentView::forPayment($this->payment($details));

        self::assertNotNull($view);
        self::assertSame('2026-08-31T12:00:00+02:00', $view->lastIpnAt?->format(\DateTimeInterface::ATOM));
    }

    public function testAPaymentOfAnotherGatewayHasNoView(): void
    {
        self::assertNull(SimplePayPaymentView::forPayment($this->payment(self::state(), 'offline')));
    }

    public function testAPaymentWithoutStateStillProducesAViewWithEmptyFields(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment([]));

        self::assertNotNull($view);
        self::assertNull($view->transactionId);
        self::assertNull($view->status);
        self::assertSame([], $view->ipnLog);
        self::assertFalse($view->repeatWarning);
    }

    public function testASingleNotificationRaisesNoWarning(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment(self::state(repeatCount: 1)));

        self::assertNotNull($view);
        self::assertFalse($view->repeatWarning);
    }

    public function testASecondNotificationAlreadyRaisesTheWarning(): void
    {
        // A határeset: 1 még nem ismétlés, de a 2. előfordulás már az. Ha a
        // küszöb csak a 3-nál kapcsolna be, ez a legfontosabb eset — a
        // legelső ismétlés — csendben maradna.
        $view = SimplePayPaymentView::forPayment($this->payment(self::state(repeatCount: 2)));

        self::assertNotNull($view);
        self::assertTrue($view->repeatWarning);
    }

    public function testARepeatedNotificationRaisesTheWarningThatOurConfirmationWasRefused(): void
    {
        // Ez az admin felület egyetlen figyelmeztetése, és a legfontosabb:
        // ha a SimplePay ismétel, a visszaigazolásunkat nem fogadták el.
        $view = SimplePayPaymentView::forPayment($this->payment(self::state(repeatCount: 3)));

        self::assertNotNull($view);
        self::assertTrue($view->repeatWarning);
    }
}
