<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A vevő visszatérése a SimplePay fizetőoldaláról.
 *
 * A SimplePay az `r` (base64 JSON) és `s` (aláírás) paraméterekkel tér vissza.
 * EZ AZ ADAT TÁJÉKOZTATÓ, NEM BIZONYÍTÉK: az aláírás miatt nem hamisítható,
 * de csak azt mondja meg, mit lát a vásárló — nem azt, hogy a pénz
 * megérkezett-e. Az állapotot mindig a `Sync` (`/query`) dönti el.
 *
 * A controller nem renderel saját oldalt: a token `afterUrl`-jére irányít,
 * vagyis a Sylius szokásos köszönő/hibaoldalára. A régi implementáció itt
 * egy párhuzamos visszajelző felületet épített a Sylius sajátja mellé.
 */
final class ReturnController
{
    private readonly LoggerInterface $logger;

    /**
     * A `logger` a `services.xml`-ben `on-invalid="null"`, tehát a konténer
     * `null`-t ad, ha nincs naplózó — a paraméter ezért nullable.
     */
    public function __construct(
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(Request $request): Response
    {
        // Invalidálás NÉLKÜL: a Sylius after-pay oldalnak még kell a token.
        $token = $this->payum->getHttpRequestVerifier()->verify($request);
        $gateway = $this->payum->getGateway($token->getGatewayName());

        $this->logReturnPayload($request);

        try {
            $gateway->execute(new Sync($token));
        } catch (\Throwable $exception) {
            // A vevő böngészője nem a hibakezelés helye. Ha a lekérdezés nem
            // megy át, az IPN úgyis megérkezik; a vevőt a Sylius szokásos
            // oldalára engedjük, a hibát pedig naplózzuk.
            $this->logger->error('A SimplePay állapot-lekérdezés nem sikerült a visszatéréskor.', [
                'event' => 'simplepay.return.sync_failed',
                'reason' => $exception->getMessage(),
            ]);
        }

        $gateway->execute(new GetHumanStatus($token));

        $this->entityManager->flush();

        return new RedirectResponse($token->getAfterUrl());
    }

    /**
     * Az `r`/`s` pár naplózása. Nem ellenőrizzük itt magunk: az aláírás-
     * ellenőrzés a protokoll-csomag dolga, és mivel az eredmény úgysem dönt
     * semmit, a hiánya vagy hibája nem törheti el a vevő oldalát.
     */
    private function logReturnPayload(Request $request): void
    {
        $r = $request->query->get('r');
        $event = $request->query->get('e');

        if (!is_string($r) || '' === $r) {
            $this->logger->info('A SimplePay visszatérés nem hozott r paramétert.', [
                'event' => 'simplepay.return.no_payload',
                'expected_event' => is_string($event) ? $event : null,
            ]);

            return;
        }

        $this->logger->info('SimplePay visszatérés érkezett.', [
            'event' => 'simplepay.return.received',
            'expected_event' => is_string($event) ? $event : null,
            // A törzset szándékosan NEM dekódoljuk itt: a nyers érték
            // elegendő a nyomon követéshez, és a dekódolás aláírás-
            // ellenőrzés nélkül félrevezető volna.
            'raw_length' => strlen($r),
        ]);
    }
}
