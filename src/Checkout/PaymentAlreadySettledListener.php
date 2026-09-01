<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Checkout;

use CodeConjure\SimplePayPayum\Exception\PaymentAlreadySettledException;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Payum\Core\Security\TokenInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * A már kifizetett rendelés capture-URL-jének újratöltését zárja le.
 *
 * A Payum `CaptureController` a `$gateway->execute(new Capture($token))` után
 * invalidálná a tokent és átirányítana — de ha a `CaptureAction`
 * `PaymentAlreadySettledException`-t dob, egyik sem fut le, és a vevő 500-as
 * oldalt kap egy kifizetett rendelésen. A kivétel maga helyes: ez akadályozza
 * meg a dupla terhelést. Csak a vevőnek szánt kimenet hiányzik mögüle.
 *
 * A figyelő ezért nem javít semmit az állapoton: lekérdezi az igazit
 * (`Sync`), frissíti a Sylius fizetés-státuszt (`GetHumanStatus`), és a token
 * `afterUrl`-jére irányít — oda, ahová a sikeres capture is vitte volna.
 */
final class PaymentAlreadySettledListener
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

    public function __invoke(ExceptionEvent $event): void
    {
        // Egy beágyazott kérés kimenete nem irányíthatja át a fő választ.
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->isAlreadySettled($event->getThrowable())) {
            return;
        }

        $token = $this->resolveToken($event);

        if (null === $token) {
            // Nincs hová vinni a vevőt. A kivétel elnyelése itt néma hiba
            // volna, ezért érintetlenül hagyjuk az eseményt.
            return;
        }

        $gateway = $this->payum->getGateway($token->getGatewayName());

        try {
            $gateway->execute(new Sync($token));
            $gateway->execute(new GetHumanStatus($token));
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            // A vevő böngészője nem a hibakezelés helye. Az állapotot az IPN
            // úgyis rendbe hozza; a vevőt engedjük a Sylius oldalára.
            $this->logger->error('A már lezárult fizetés állapot-lekérdezése nem sikerült.', [
                'event' => 'simplepay.capture.already_settled_sync_failed',
                'reason' => $exception->getMessage(),
            ]);
        }

        $this->logger->info('Egy már lezárult fizetés capture-címét töltötték újra.', [
            'event' => 'simplepay.capture.already_settled',
            'after_url' => $token->getAfterUrl(),
        ]);

        $event->setResponse(new RedirectResponse($token->getAfterUrl()));
    }

    /**
     * A kivétel a Payum és a Symfony rétegein át becsomagolva is megérkezhet,
     * ezért az egész okláncot végigjárjuk.
     */
    private function isAlreadySettled(\Throwable $throwable): bool
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof PaymentAlreadySettledException) {
                return true;
            }
        }

        return false;
    }

    /**
     * A tokent NEM invalidáljuk: a Sylius after-pay oldalnak még kell, és a
     * `PaymentAlreadySettledException` maga akadályozza meg, hogy az újra
     * meghívott capture bármit is elindítson.
     */
    private function resolveToken(ExceptionEvent $event): ?TokenInterface
    {
        try {
            return $this->payum->getHttpRequestVerifier()->verify($event->getRequest());
        } catch (\Throwable $exception) {
            $this->logger->warning('A lezárult fizetés kéréséhez nem tartozik feloldható Payum token.', [
                'event' => 'simplepay.capture.already_settled_no_token',
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
