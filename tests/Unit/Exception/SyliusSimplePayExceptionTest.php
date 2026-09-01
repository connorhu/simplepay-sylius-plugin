<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Exception;

use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Exception\SyliusSimplePayException;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A három konkrét kivétel mindegyike a közös {@see SyliusSimplePayException}
 * interfészt implementálja — ez teszi lehetővé, hogy egy hívó fél egyetlen
 * `catch (SyliusSimplePayException $e)` ággal fogja el a plugin összes
 * kivételét. Ez a teszt őrzi ezt a szerződést.
 */
final class SyliusSimplePayExceptionTest extends TestCase
{
    /** @return iterable<string, array{class-string<SyliusSimplePayException>}> */
    public static function concreteExceptions(): iterable
    {
        yield UnrepresentableAmountException::class => [UnrepresentableAmountException::class];
        yield IncompletePaymentException::class => [IncompletePaymentException::class];
        yield GatewayMismatchException::class => [GatewayMismatchException::class];
    }

    /** @param class-string<SyliusSimplePayException> $exceptionClass */
    #[DataProvider('concreteExceptions')]
    public function testItImplementsTheCommonMarkerInterface(string $exceptionClass): void
    {
        $exception = new $exceptionClass('teszt üzenet');

        self::assertInstanceOf(SyliusSimplePayException::class, $exception);
    }

    /** @param class-string<SyliusSimplePayException> $exceptionClass */
    #[DataProvider('concreteExceptions')]
    public function testItIsARuntimeException(string $exceptionClass): void
    {
        $exception = new $exceptionClass('teszt üzenet');

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    /** @param class-string<SyliusSimplePayException> $exceptionClass */
    #[DataProvider('concreteExceptions')]
    public function testItCarriesTheMessageItWasConstructedWith(string $exceptionClass): void
    {
        $exception = new $exceptionClass('a hiba oka');

        self::assertSame('a hiba oka', $exception->getMessage());
    }
}
