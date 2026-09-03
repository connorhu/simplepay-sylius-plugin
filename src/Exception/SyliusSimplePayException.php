<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/** A plugin minden kivételének közös interfésze — egyetlen `catch` mindet elkapja. */
interface SyliusSimplePayException extends \Throwable
{
}
