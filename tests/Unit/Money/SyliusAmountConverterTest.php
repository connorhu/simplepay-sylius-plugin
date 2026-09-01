<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Money;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyliusAmountConverterTest extends TestCase
{
    /** @return iterable<string, array{int, Currency, int}> */
    public static function amounts(): iterable
    {
        yield '1000 Ft' => [100000, Currency::HUF, 1000];
        yield '1 Ft' => [100, Currency::HUF, 1];
        yield 'nulla forint' => [0, Currency::HUF, 0];
        yield 'negatív forint (jóváírásnál előfordul)' => [-50000, Currency::HUF, -500];
        yield '10 euró' => [1000, Currency::EUR, 1000];
        yield '1 eurocent' => [1, Currency::EUR, 1];
        yield 'nulla euró' => [0, Currency::EUR, 0];
        yield '10 dollár' => [1000, Currency::USD, 1000];
    }

    #[DataProvider('amounts')]
    public function testItConvertsToTheCurrencyMinorUnit(
        int $syliusAmount,
        Currency $currency,
        int $expected,
    ): void {
        self::assertSame($expected, SyliusAmountConverter::toMinorUnits($syliusAmount, $currency));
    }

    #[DataProvider('amounts')]
    public function testTheConversionRoundTrips(
        int $syliusAmount,
        Currency $currency,
        int $minorUnits,
    ): void {
        self::assertSame($syliusAmount, SyliusAmountConverter::toSyliusAmount($minorUnits, $currency));
    }

    public function testAForintAmountWithFractionalPartIsLoudNotRounded(): void
    {
        // 100050 Sylius-egység = 1000,50 Ft. A SimplePay HUF-nál egész
        // forintot vár; a csendes kerekítés pénzügyi hiba volna.
        $this->expectException(UnrepresentableAmountException::class);
        $this->expectExceptionMessage('100050');

        SyliusAmountConverter::toMinorUnits(100050, Currency::HUF);
    }

    public function testTheExceptionNamesTheCurrencySoTheCauseIsObvious(): void
    {
        $this->expectException(UnrepresentableAmountException::class);
        $this->expectExceptionMessage('HUF');

        SyliusAmountConverter::toMinorUnits(1, Currency::HUF);
    }

    public function testAEuroAmountNeverNeedsRoundingBecauseTheExponentsMatch(): void
    {
        // EUR-nál a Sylius ábrázolás és a pénznem alegysége egybeesik,
        // tehát semmilyen érték nem lehet ábrázolhatatlan.
        self::assertSame(1, SyliusAmountConverter::toMinorUnits(1, Currency::EUR));
        self::assertSame(99, SyliusAmountConverter::toMinorUnits(99, Currency::EUR));
    }

    public function testANegativeForintWithFractionalPartIsAlsoRejected(): void
    {
        $this->expectException(UnrepresentableAmountException::class);

        SyliusAmountConverter::toMinorUnits(-100050, Currency::HUF);
    }
}
