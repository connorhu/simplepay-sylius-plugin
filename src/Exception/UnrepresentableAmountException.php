<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A Sylius összeg nem ábrázolható a pénznem valódi alegységében.
 *
 * A Sylius pénznemtől függetlenül 1/100 egységben tárol, tehát HUF esetén
 * elő tud állni olyan érték, ami tört forintot jelentene. A SimplePay egész
 * forintot vár. A csendes kerekítés itt pénzügyi hiba volna, ezért hangos.
 */
final class UnrepresentableAmountException extends \RuntimeException implements SyliusSimplePayException
{
}
