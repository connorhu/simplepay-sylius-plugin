<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\View;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\IpnLogEntry;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Read-model az admin rendelés-oldalhoz.
 *
 * Ez váltja ki az alkalmazás `Payment` entitásának SimplePay-metódusait:
 * egy entitásnak nem dolga a gateway details-ének értelmezése.
 */
final readonly class SimplePayPaymentView
{
    /** @param list<IpnLogEntry> $ipnLog */
    private function __construct(
        public ?string $transactionId,
        public ?string $status,
        public string $environment,
        public ?\DateTimeImmutable $lastIpnAt,
        public bool $repeatWarning,
        public array $ipnLog,
    ) {
    }

    public static function forPayment(PaymentInterface $payment): ?self
    {
        $method = $payment->getMethod();

        if (null === $method || !GatewayConfigReader::isSimplePay($method)) {
            return null;
        }

        $state = TransactionState::fromArray(self::stateArray($payment->getDetails()));
        $lastEntry = [] === $state->ipnLog ? null : $state->ipnLog[count($state->ipnLog) - 1];

        return new self(
            transactionId: $state->transactionId,
            status: $state->status?->value,
            environment: GatewayConfigReader::read($method)->environment->value,
            lastIpnAt: $lastEntry?->receivedAt,
            // Ha bármelyik bejegyzés ismétlődött, a visszaigazolásunkat
            // nem fogadták el. Ez a felület egyetlen figyelmeztetése.
            repeatWarning: self::hasRepeat($state),
            ipnLog: $state->ipnLog,
        );
    }

    private static function hasRepeat(TransactionState $state): bool
    {
        foreach ($state->ipnLog as $entry) {
            if ($entry->repeatCount > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private static function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
