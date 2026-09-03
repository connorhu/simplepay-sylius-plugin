<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Order;

use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderReferenceTest extends TestCase
{
    public function testItBuildsTheReferenceWithTheOrderNumberFirst(): void
    {
        // A rendelésszám elöl marad, hogy a SimplePay kereskedői panelben
        // felismerhető legyen — ott ember nézi.
        self::assertSame('000000042-17-1', OrderReference::build('000000042', 17, 1));
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function references(): iterable
    {
        yield 'egyszerű rendelésszám' => ['000000042-17-1', '000000042', 17, 1];
        yield 'kötőjeles rendelésszám' => ['EZ-2026-0042-17-2', 'EZ-2026-0042', 17, 2];
        yield 'több kötőjel' => ['A-B-C-D-5-9', 'A-B-C-D', 5, 9];
        yield 'nagy azonosítók' => ['R1-123456-99', 'R1', 123456, 99];
    }

    #[DataProvider('references')]
    public function testItParsesFromTheRightSoDashesInTheOrderNumberAreSafe(
        string $reference,
        string $orderNumber,
        int $paymentId,
        int $attempt,
    ): void {
        $parsed = OrderReference::parse($reference);

        self::assertSame($orderNumber, $parsed->orderNumber);
        self::assertSame($paymentId, $parsed->paymentId);
        self::assertSame($attempt, $parsed->attempt);
    }

    #[DataProvider('references')]
    public function testBuildAndParseAreInverses(
        string $reference,
        string $orderNumber,
        int $paymentId,
        int $attempt,
    ): void {
        self::assertSame($reference, OrderReference::build($orderNumber, $paymentId, $attempt));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'nincs elég szegmens' => ['000000042-17'];
        yield 'nem szám a végén' => ['000000042-17-első'];
        yield 'nem szám középen' => ['000000042-tizenhét-1'];
        yield 'üres rendelésszám' => ['-17-1'];
        yield 'üres string' => [''];
        yield 'csak számok, de kevés' => ['1-2'];
        yield 'negatív azonosító' => ['R-{-1}-1'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedReferenceIsRejectedRatherThanPartiallyParsed(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderReference::parse($reference);
    }

    #[DataProvider('malformed')]
    public function testTryParseReturnsNullForTheSameMalformedInputs(string $reference): void
    {
        self::assertNull(OrderReference::tryParse($reference));
    }

    public function testALeadingZeroInTheCounterIsRejectedBecauseItIsNotWhatWeEmit(): void
    {
        // Nem szigorúskodás: ha elfogadnánk, a build/parse nem volna
        // kölcsönösen egyértelmű, és két különböző string ugyanarra a
        // fizetésre mutatna.
        $this->expectException(\InvalidArgumentException::class);

        OrderReference::parse('R-017-1');
    }
}
