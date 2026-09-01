<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class GatewayConfigReaderTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function paymentMethod(
        ?string $factoryName,
        array $config = [],
        ?string $gatewayName = 'simplepay_gateway',
    ): PaymentMethodInterface {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);
        $gatewayConfig->method('getGatewayName')->willReturn($gatewayName);

        $paymentMethod = $this->createStub(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn(null === $factoryName ? null : $gatewayConfig);
        $paymentMethod->method('getCode')->willReturn('simplepay');

        return $paymentMethod;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ];
    }

    public function testItReadsTheSettingsFromASimplePayPaymentMethod(): void
    {
        $settings = GatewayConfigReader::read($this->paymentMethod('simplepay', self::config()));

        self::assertSame('PUBLICTESTHUF', $settings->merchant);
        self::assertSame(Environment::Sandbox, $settings->environment);
        self::assertSame(Currency::HUF, $settings->currency);
    }

    public function testItRefusesAPaymentMethodOfAnotherGateway(): void
    {
        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('offline');

        GatewayConfigReader::read($this->paymentMethod('offline', self::config()));
    }

    public function testItRefusesAPaymentMethodWithoutAGatewayConfig(): void
    {
        $this->expectException(GatewayMismatchException::class);

        GatewayConfigReader::read($this->paymentMethod(null));
    }

    public function testIsSimplePayAnswersWithoutThrowing(): void
    {
        self::assertTrue(GatewayConfigReader::isSimplePay($this->paymentMethod('simplepay', self::config())));
        self::assertFalse(GatewayConfigReader::isSimplePay($this->paymentMethod('offline')));
        self::assertFalse(GatewayConfigReader::isSimplePay($this->paymentMethod(null)));
    }

    public function testAnUnknownCurrencyInTheConfigIsNamedInTheError(): void
    {
        $config = self::config();
        $config['currency'] = 'GBP';

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('GBP');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAnUnknownEnvironmentInTheConfigIsNamedInTheError(): void
    {
        $config = self::config();
        $config['environment'] = 'staging';

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('staging');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAMissingCurrencyIsLoudNotDefaultedToForint(): void
    {
        $config = self::config();
        unset($config['currency']);

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('currency');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAMissingMerchantIsLoud(): void
    {
        $config = self::config();
        unset($config['merchant']);

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('merchant');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAnEmptyStringMerchantIsLoudNotSilentlyAccepted(): void
    {
        $config = self::config();
        $config['merchant'] = '';

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('merchant');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testTheStringZeroMerchantIsALegitimateValue(): void
    {
        $config = self::config();
        $config['merchant'] = '0';

        $settings = GatewayConfigReader::read($this->paymentMethod('simplepay', $config));

        self::assertSame('0', $settings->merchant);
    }

    /**
     * R27 (3. találat): a `gatewayName()`-t korábban szó szerint
     * duplikálva tartotta az `IpnController` és a `RefundCommand` — ez a
     * teszt a KIEMELT, közös felolvasást fedi le.
     */
    public function testGatewayNameReadsThePayumGatewayNameFromTheConfig(): void
    {
        $paymentMethod = $this->paymentMethod('simplepay', self::config(), gatewayName: 'simplepay_46f2');

        self::assertSame('simplepay_46f2', GatewayConfigReader::gatewayName($paymentMethod));
    }

    public function testGatewayNameIsLoudWhenTheGatewayConfigHasNoGatewayName(): void
    {
        $this->expectException(GatewayMismatchException::class);

        GatewayConfigReader::gatewayName($this->paymentMethod('simplepay', self::config(), gatewayName: null));
    }

    public function testGatewayNameIsLoudWhenThereIsNoGatewayConfigAtAll(): void
    {
        $this->expectException(GatewayMismatchException::class);

        GatewayConfigReader::gatewayName($this->paymentMethod(null));
    }
}
