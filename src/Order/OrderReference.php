<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Order;

/**
 * A SimplePay `orderRef` séma: `{rendelésszám}-{paymentId}-{próbálkozás}`.
 *
 * MIÉRT NEM CSAK A RENDELÉSSZÁM (mint a régi implementációban): a Sylius
 * sikertelen fizetés után ÚJ `Payment` entitást hoz létre ugyanahhoz a
 * rendeléshez, és egy lejárt tranzakció újraindítása is új hivatkozást
 * igényel. Közös `orderRef` mellett a `QueryResponse::byOrderRef()` az
 * ELSŐ találatot adja vissza — néma keveredés két tranzakció között.
 *
 * A rendelésszám elöl marad, mert a SimplePay kereskedői panelben ember
 * nézi, és ott a rendelésszám az, amit keres. Ezért a visszafejtés JOBBRÓL
 * történik: az utolsó két kötőjeles szegmens a két szám, minden más előtte
 * a rendelésszám — így a kötőjelet tartalmazó rendelésszám is biztonságos.
 *
 * NYITOTT KÉRDÉS: az `orderRef` maximális hosszát a hivatalos
 * `PaymentService_SimplePay_2.x_Payment_HU_260504.pdf` dokumentáció
 * ebben a környezetben nem volt elérhető, ezért nem ellenőrizhető — ez
 * dokumentált ismeretlen marad. A plugin nem csonkít és nem talál ki
 * korlátot: inkább hangosan elbukna, ha a SimplePay egy túl hosszú
 * hivatkozást visszautasítana.
 */
final readonly class OrderReference
{
    private const string PATTERN = '/^(?<orderNumber>.+)-(?<paymentId>0|[1-9]\d*)-(?<attempt>0|[1-9]\d*)$/';

    public function __construct(
        public string $orderNumber,
        public int $paymentId,
        public int $attempt,
    ) {
    }

    public static function build(string $orderNumber, int $paymentId, int $attempt): string
    {
        return sprintf('%s-%d-%d', $orderNumber, $paymentId, $attempt);
    }

    public static function parse(string $reference): self
    {
        return self::tryParse($reference) ?? throw new \InvalidArgumentException(sprintf(
            'A(z) "%s" nem érvényes SimplePay hivatkozás. A várt alak: '
            . '{rendelésszám}-{paymentId}-{próbálkozás}.',
            $reference,
        ));
    }

    public static function tryParse(string $reference): ?self
    {
        if (1 !== preg_match(self::PATTERN, $reference, $matches)) {
            return null;
        }

        return new self(
            orderNumber: $matches['orderNumber'],
            paymentId: (int) $matches['paymentId'],
            attempt: (int) $matches['attempt'],
        );
    }

    public function toString(): string
    {
        return self::build($this->orderNumber, $this->paymentId, $this->attempt);
    }
}
