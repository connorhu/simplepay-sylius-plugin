<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Language;

use CodeConjure\SimplePay\Language;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;

/**
 * Sylius locale → a SimplePay fizetőoldal nyelve.
 *
 * A leképezés explicit, és az ismeretlen locale HANGOS HIBA, nem csendes
 * magyar alapértelmezés. A bolt ma egyetlen locale-t használ (`hu_HU`),
 * tehát ez ma nem sülhet el — egy jövőbeli locale hozzáadásakor viszont
 * azonnal látszik, hogy a fizetőoldal nyelvéről dönteni kell.
 *
 * A protokoll-csomag `Language` enumja három nyelvet ismer; a SimplePay
 * többet is támogat, de azokat az 1. fázis nem modellezte.
 */
final class LocaleToLanguageMap
{
    /** @var array<string, Language> */
    private const array MAP = [
        'hu' => Language::Hu,
        'en' => Language::En,
        'de' => Language::De,
    ];

    public static function resolve(?string $localeCode): Language
    {
        if (null === $localeCode || '' === trim($localeCode)) {
            throw new IncompletePaymentException(
                'A rendeléshez nincs locale, ezért a SimplePay fizetőoldal nyelve nem határozható meg.',
            );
        }

        $primary = strtolower(preg_split('/[_-]/', trim($localeCode))[0] ?? '');

        return self::MAP[$primary] ?? throw new IncompletePaymentException(sprintf(
            'A(z) "%s" locale-hoz nincs SimplePay fizetőoldal-nyelv rendelve. A támogatottak: %s. '
            . 'Ha új nyelvet vezetsz be a boltban, itt kell dönteni a fizetőoldal nyelvéről.',
            $localeCode,
            implode(', ', array_map(
                static fn (Language $language): string => $language->value,
                array_values(self::MAP),
            )),
        ));
    }
}
