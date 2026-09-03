<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A fizetésből hiányzik egy adat, ami nélkül a SimplePay kérés nem
 * állítható össze: rendelés, vevő e-mail, számlázási cím vagy locale.
 *
 * Nincs helyettesítő érték. Egy üres e-mail címmel elküldött kérés vagy
 * elbukik a SimplePay-nél, vagy — rosszabb esetben — átmegy, és a vevő
 * nem kap visszaigazolást.
 */
final class IncompletePaymentException extends \RuntimeException implements SyliusSimplePayException
{
}
