<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Money;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;

/**
 * Sylius összeg ↔ a pénznem valódi alegysége.
 *
 * A Sylius PÉNZNEMTŐL FÜGGETLENÜL 1/100 egységben tárol: 1000 Ft → 100000,
 * 10 EUR → 1000. A `Money::fromMinorUnits()` viszont a pénznem valódi
 * kitevője szerinti alegységet várja: HUF-nál egész forintot, EUR-nál centet.
 *
 * | pénznem | kitevő | Sylius | osztó | SimplePay      |
 * |---------|--------|--------|-------|----------------|
 * | HUF     | 0      | 100000 | 100   | 1000 (forint)  |
 * | EUR     | 2      | 1000   | 1     | 1000 (cent)    |
 * | USD     | 2      | 1000   | 1     | 1000 (cent)    |
 *
 * SOHA NEM KEREKÍT. A régi implementáció minden pénznemre két tizedessel
 * osztott 100-zal — HUF-nál ez százszoros eltérés lett volna.
 */
final class SyliusAmountConverter
{
    public static function toMinorUnits(int $syliusAmount, Currency $currency): int
    {
        $divisor = self::divisor($currency);

        if (0 !== $syliusAmount % $divisor) {
            throw new UnrepresentableAmountException(sprintf(
                'A(z) %d Sylius-egység nem ábrázolható %s alegységben: a %s legkisebb '
                . 'egysége %d Sylius-egységnek felel meg, és a kerekítés pénzügyi hiba volna.',
                $syliusAmount,
                $currency->value,
                $currency->value,
                $divisor,
            ));
        }

        return intdiv($syliusAmount, $divisor);
    }

    public static function toSyliusAmount(int $minorUnits, Currency $currency): int
    {
        return $minorUnits * self::divisor($currency);
    }

    /**
     * A Sylius mindig két tizedessel ábrázol; az osztó a különbség a Sylius
     * rögzített kitevője (2) és a pénznem valódi kitevője között.
     */
    private static function divisor(Currency $currency): int
    {
        return 10 ** (2 - $currency->exponent());
    }
}
