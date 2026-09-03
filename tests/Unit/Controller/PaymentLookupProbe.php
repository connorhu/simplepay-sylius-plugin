<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Controller;

/**
 * Jelölő, amit a payment repository double `find()` hívása helyez az
 * `IpnControllerTest` közös `$executed` naplójába.
 *
 * A gateway-double eddig is naplózta a `ResolveSimplePayIpn` és a `Notify`
 * kéréseket, de a `paymentRepository->find()` hívás IDŐZÍTÉSE — a resolve
 * ELŐTT vagy UTÁN történt-e — láthatatlan maradt, mert az nem a gateway-en
 * megy át. Enélkül egy olyan hiba, ami a fizetést a MÉG HITELESÍTETLEN
 * törzsből olvasott `orderRef` alapján keresné meg, észrevétlen maradna.
 */
final readonly class PaymentLookupProbe
{
    public function __construct(public mixed $paymentId)
    {
    }
}
