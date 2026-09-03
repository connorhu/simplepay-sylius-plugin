<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Twig;

use CodeConjure\SyliusSimplePayPlugin\View\SimplePayPaymentView;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A `simplepay_payment_view()` Twig-függvény: az admin sablon ezen keresztül
 * jut hozzá a `SimplePayPaymentView` read-modelhez.
 */
final class SimplePayExtension extends AbstractExtension
{
    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'simplepay_payment_view',
                static fn (PaymentInterface $payment): ?SimplePayPaymentView => SimplePayPaymentView::forPayment($payment),
            ),
        ];
    }
}
