<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Resources\Views\Admin;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\Twig\SimplePayExtension;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Az order_show_payment.html.twig sablon lerendereli önmagát — a
 * GatewayConfigurationTemplateTest (Task 7) mintáját követve: nem elég,
 * hogy a `SimplePayPaymentView` mezői helyesek, a sablon elágazásainak
 * (van-e nézet, van-e ismétlés, üres-e a napló) is a helyes ágat kell
 * futtatniuk.
 *
 * A `trans` szűrő itt SZÁNDÉKOSAN nem kap valódi fordítót — a fordítási
 * kulcsok maguk jelennek meg kimenetként, ez elég ahhoz, hogy a sablon
 * elágazásait ellenőrizzük anélkül, hogy a fordítófájltól függenénk.
 */
final class OrderShowPaymentTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        $projectRoot = \dirname(__DIR__, 5);

        $loader = new FilesystemLoader([$projectRoot . '/src/Resources/views']);

        $twig = new Environment($loader);
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new SimplePayExtension());

        return $twig;
    }

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
    private static function stateWithIpnLogEntry(int $repeatCount): array
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

    private function render(PaymentInterface $payment): string
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getPayments')->willReturn(new ArrayCollection([$payment]));

        $hookableMetadata = new \stdClass();
        $hookableMetadata->context = new \stdClass();
        $hookableMetadata->context->order = $order;

        return $this->twig()->render('admin/order_show_payment.html.twig', [
            'hookable_metadata' => $hookableMetadata,
        ]);
    }

    public function testAPaymentOfAnotherGatewayRendersNoCard(): void
    {
        // Nincs SimplePay-nézet ehhez a fizetéshez, tehát a kártya (és minden
        // fordítási kulcsa) egyáltalán nem jelenhet meg.
        $html = $this->render($this->payment(self::stateWithIpnLogEntry(1), 'offline'));

        self::assertStringNotContainsString('codeconjure_simplepay.admin.heading', $html);
    }

    public function testAPaymentWithoutStoredStateRendersAnEmptyCardWithoutTheLogTable(): void
    {
        $html = $this->render($this->payment([]));

        self::assertStringContainsString('codeconjure_simplepay.admin.heading', $html);
        self::assertStringContainsString('—', $html);
        self::assertStringNotContainsString('codeconjure_simplepay.admin.repeat_warning', $html);
        self::assertStringNotContainsString('codeconjure_simplepay.admin.ipn_log', $html);
    }

    public function testASingleNotificationRendersTheLogWithoutAWarning(): void
    {
        $html = $this->render($this->payment(self::stateWithIpnLogEntry(1)));

        self::assertStringContainsString('codeconjure_simplepay.admin.ipn_log', $html);
        self::assertStringNotContainsString('codeconjure_simplepay.admin.repeat_warning', $html);
    }

    public function testARepeatedNotificationRendersTheWarningAndTheLogRow(): void
    {
        $html = $this->render($this->payment(self::stateWithIpnLogEntry(3)));

        self::assertStringContainsString('codeconjure_simplepay.admin.repeat_warning', $html);
        self::assertStringContainsString('codeconjure_simplepay.admin.ipn_log', $html);
        self::assertStringContainsString('>3<', $html);
    }
}
