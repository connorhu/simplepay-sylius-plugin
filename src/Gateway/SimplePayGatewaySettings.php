<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;

/**
 * A gateway konfigurációjából kiolvasott, tipizált beállítások.
 *
 * A `secretKey` SZÁNDÉKOSAN nincs itt: ezt az objektumot naplózásra és
 * ellenőrzésre használjuk, a titok pedig a Payum gateway-en belül marad.
 * Egy titkot hordozó DTO előbb-utóbb megjelenik egy `var_dump`-ban vagy
 * egy hibajelentésben.
 */
final readonly class SimplePayGatewaySettings
{
    public function __construct(
        public string $merchant,
        public Environment $environment,
        public Currency $currency,
    ) {
    }
}
