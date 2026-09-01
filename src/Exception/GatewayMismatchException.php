<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A fizetés és a gateway nem illik össze: eltérő pénznem, vagy a payment
 * method nem a `simplepay` factory-t használja.
 *
 * A pénznem-eltérés valódi eset: a SimplePay merchant azonosító pénznemhez
 * kötött, egy merchant egy pénznemet fogad. Enélkül az ellenőrzés nélkül a
 * kérés kimenne, és a SimplePay egy nehezen értelmezhető hibakóddal
 * utasítaná el.
 */
final class GatewayMismatchException extends \RuntimeException implements SyliusSimplePayException
{
}
