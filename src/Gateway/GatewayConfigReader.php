<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * A Sylius gateway konfigurációjának tipizált olvasása.
 *
 * A konfiguráció egy `array<string, mixed>` az adatbázisban, tehát minden
 * érték gyanús, amíg nem ellenőriztük. Hiányzó vagy ismeretlen értékre
 * hangos hiba jár, ami MEGNEVEZI a kapott értéket — egy „hibás konfiguráció"
 * üzenetből az admin nem tudja, mit írjon át.
 */
final class GatewayConfigReader
{
    public const string FACTORY_NAME = 'simplepay';

    public static function read(PaymentMethodInterface $paymentMethod): SimplePayGatewaySettings
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $factoryName = $gatewayConfig?->getFactoryName();

        if (self::FACTORY_NAME !== $factoryName) {
            throw new GatewayMismatchException(sprintf(
                'A(z) "%s" fizetési mód nem SimplePay: a gateway factory neve "%s".',
                (string) $paymentMethod->getCode(),
                $factoryName ?? '(nincs gateway konfiguráció)',
            ));
        }

        $config = $gatewayConfig->getConfig();

        return new SimplePayGatewaySettings(
            merchant: self::string($config, 'merchant'),
            environment: self::enum(Environment::class, self::string($config, 'environment'), 'environment'),
            currency: self::enum(Currency::class, self::string($config, 'currency'), 'currency'),
        );
    }

    public static function isSimplePay(PaymentMethodInterface $paymentMethod): bool
    {
        return self::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName();
    }

    /** @param array<array-key, mixed> $config */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            throw new GatewayMismatchException(sprintf(
                'A SimplePay gateway konfigurációjából hiányzik a "%s" beállítás.',
                $key,
            ));
        }

        return trim($value);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, string $value, string $key): \BackedEnum
    {
        return $enum::tryFrom($value) ?? throw new GatewayMismatchException(sprintf(
            'A SimplePay gateway "%s" beállításának "%s" értéke ismeretlen. Az ismertek: %s.',
            $key,
            $value,
            implode(', ', array_column($enum::cases(), 'value')),
        ));
    }
}
