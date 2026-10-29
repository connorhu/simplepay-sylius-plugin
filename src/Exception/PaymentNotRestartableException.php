<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

use CodeConjure\SimplePay\TransactionStatus;

/**
 * Egy lejárt helyi állapotú fizetésre NEM indulhat új `/start`: a SimplePay
 * szerint a tranzakció még folyamatban van (az összeg lefoglalva lehet, és
 * még lezárulhat), vagy az állapota nem kérdezhető le. Egy új tranzakció
 * mindkét esetben második terhelést kockáztatna.
 *
 * A `PaymentAlreadySettledListener` kezeli: a vevő a token `afterUrl`-jére,
 * a Sylius szokásos oldalára kerül, ami a függő állapotot mutatja.
 */
final class PaymentNotRestartableException extends \RuntimeException implements SyliusSimplePayException
{
    public static function inProgress(TransactionStatus $status): self
    {
        return new self(sprintf(
            'A SimplePay tranzakció még folyamatban van (%s); új tranzakció nem indul.',
            $status->value,
        ));
    }

    public static function stateUnknown(\Throwable $previous): self
    {
        return new self(
            'A SimplePay tranzakció állapota nem kérdezhető le; új tranzakció nem indul.',
            previous: $previous,
        );
    }
}
