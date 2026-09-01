<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Language;

use CodeConjure\SimplePay\Language;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Language\LocaleToLanguageMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleToLanguageMapTest extends TestCase
{
    /** @return iterable<string, array{string, Language}> */
    public static function locales(): iterable
    {
        yield 'hu_HU' => ['hu_HU', Language::Hu];
        yield 'csak hu' => ['hu', Language::Hu];
        yield 'en_US' => ['en_US', Language::En];
        yield 'en_GB' => ['en_GB', Language::En];
        yield 'de_DE' => ['de_DE', Language::De];
        yield 'de_AT' => ['de_AT', Language::De];
        yield 'kötőjeles alak' => ['en-GB', Language::En];
        yield 'nagybetűs alak' => ['HU_hu', Language::Hu];
    }

    #[DataProvider('locales')]
    public function testItResolvesTheKnownLocales(string $localeCode, Language $expected): void
    {
        self::assertSame($expected, LocaleToLanguageMap::resolve($localeCode));
    }

    public function testAnUnmappedLocaleIsLoudRatherThanSilentlyHungarian(): void
    {
        // A bolt ma egyetlen locale-t használ (hu_HU), tehát ez ma nem sülhet
        // el. Egy jövőbeli locale hozzáadásakor viszont AZONNAL látszik, hogy
        // a fizetőoldal nyelvéről dönteni kell — ahelyett, hogy egy francia
        // vevő csendben magyar fizetőoldalt kapna.
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('fr_FR');

        LocaleToLanguageMap::resolve('fr_FR');
    }

    public function testTheErrorNamesTheSupportedLanguages(): void
    {
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('HU');

        LocaleToLanguageMap::resolve('fr_FR');
    }

    public function testAMissingLocaleIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        LocaleToLanguageMap::resolve(null);
    }

    public function testAnEmptyLocaleIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        LocaleToLanguageMap::resolve('');
    }
}
