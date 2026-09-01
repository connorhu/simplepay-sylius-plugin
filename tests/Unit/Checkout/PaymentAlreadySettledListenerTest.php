<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Checkout;

use CodeConjure\SimplePayPayum\Exception\PaymentAlreadySettledException;
use CodeConjure\SyliusSimplePayPlugin\Checkout\PaymentAlreadySettledListener;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Payum\Core\Security\HttpRequestVerifierInterface;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PaymentAlreadySettledListenerTest extends TestCase
{
    private const string AFTER_URL = 'https://bolt.hu/rendeles/koszonjuk';

    /**
     * @param list<object> $executed
     */
    private function listener(
        array &$executed,
        ?HttpRequestVerifierInterface $verifier = null,
        ?\Throwable $syncThrows = null,
        ?EntityManagerInterface $entityManager = null,
        ?LoggerInterface $logger = null,
    ): PaymentAlreadySettledListener {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn(self::AFTER_URL);
        $token->method('getGatewayName')->willReturn('simplepay');

        if (null === $verifier) {
            $verifier = $this->createStub(HttpRequestVerifierInterface::class);
            $verifier->method('verify')->willReturn($token);
        }

        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use (&$executed, $syncThrows): void {
                $executed[] = $request;

                if ($request instanceof Sync && null !== $syncThrows) {
                    throw $syncThrows;
                }
            },
        );

        $payum = $this->createStub(Payum::class);
        $payum->method('getHttpRequestVerifier')->willReturn($verifier);
        $payum->method('getGateway')->willReturn($gateway);

        return new PaymentAlreadySettledListener(
            $payum,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $logger ?? new NullLogger(),
        );
    }

    private function event(\Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/payment/capture/token-hash-123'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function settled(): PaymentAlreadySettledException
    {
        return new PaymentAlreadySettledException('A fizetés már lezárult.');
    }

    public function testItRedirectsToTheTokenAfterUrlInsteadOfLettingTheErrorPageThrough(): void
    {
        $executed = [];
        $event = $this->event($this->settled());

        $this->listener($executed)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AFTER_URL, $response->getTargetUrl());
    }

    public function testItSyncsBeforeItReadsTheStatusSoTheOrderShowsWhatReallyHappened(): void
    {
        // A kivétel csak annyit mond, hogy a TÁROLT státusz lezárt. Az
        // átirányítás előtt lekérdezzük az igazit, hogy a Sylius oldala ne
        // egy elavult details alapján döntsön.
        $executed = [];

        $this->listener($executed)($this->event($this->settled()));

        self::assertInstanceOf(Sync::class, $executed[0]);
        self::assertInstanceOf(GetHumanStatus::class, $executed[1]);
    }

    public function testItLeavesEveryOtherExceptionAlone(): void
    {
        $executed = [];
        $event = $this->event(new \RuntimeException('valami más'));

        $this->listener($executed)($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $executed, 'Idegen kivételre semmilyen Payum kérés nem futhat.');
    }

    public function testItFindsTheExceptionWhenItArrivesAsAPreviousCause(): void
    {
        // A Payum és a Symfony rétegei becsomagolhatják a kivételt; a
        // felismerés az egész okláncra megy.
        $executed = [];
        $event = $this->event(new \RuntimeException('burok', 0, $this->settled()));

        $this->listener($executed)($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testItIgnoresSubRequests(): void
    {
        // Egy beágyazott kérés kimenete nem irányíthatja át a fő választ.
        $executed = [];
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/payment/capture/token-hash-123'),
            HttpKernelInterface::SUB_REQUEST,
            $this->settled(),
        );

        $this->listener($executed)($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $executed);
    }

    public function testAFailedSyncStillRedirectsRatherThanShowingTheError(): void
    {
        // Ugyanaz az elv, mint a ReturnControllerben: a vevő böngészője nem a
        // hibakezelés helye. Az állapotot úgyis az IPN hozza rendbe.
        $executed = [];
        $event = $this->event($this->settled());

        $this->listener($executed, syncThrows: new \RuntimeException('hálózati hiba'))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AFTER_URL, $response->getTargetUrl());
    }

    public function testWithoutAResolvableTokenTheOriginalExceptionStands(): void
    {
        // Ha nincs token a kérésben, nincs afterUrl sem, ahová vihetnénk a
        // vevőt. Ilyenkor a kivétel maradjon — az elnyelése néma hibát adna.
        $executed = [];
        $verifier = $this->createStub(HttpRequestVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new NotFoundHttpException('nincs token'));

        $event = $this->event($this->settled());

        $this->listener($executed, verifier: $verifier)($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $executed);
    }

    public function testItNeverInvalidatesTheTokenBecauseTheAfterPayPageStillNeedsIt(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn(self::AFTER_URL);
        $token->method('getGatewayName')->willReturn('simplepay');

        $verifier = $this->createMock(HttpRequestVerifierInterface::class);
        $verifier->method('verify')->willReturn($token);
        $verifier->expects($this->never())->method('invalidate');

        $executed = [];
        $event = $this->event($this->settled());

        $this->listener($executed, verifier: $verifier)($event);

        // Az invalidate() elmaradását a mock never() elvárása őrzi; az
        // átirányítás azt bizonyítja, hogy a figyelő tényleg végigfutott.
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testItFlushesTheUpdatedStatusSoTheOrderShowsItOnTheNextLoad(): void
    {
        // A GetHumanStatus önmagában csak a memóriabeli entitást módosítja;
        // flush() nélkül a Sylius oldal a régi, lezáratlan státuszt látná.
        $executed = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->listener($executed, entityManager: $entityManager)($this->event($this->settled()));
    }

    public function testItFindsTheExceptionWhenItIsNestedTwoLevelsDeep(): void
    {
        // Az okláncot nem csak egy szintig, hanem a végéig kell bejárni: egy
        // olyan mutáció, ami csak egy `getPrevious()`-t old fel, ezen a
        // teszten már elbukna, míg az eggyel beágyazott esetet még átengedné.
        $executed = [];
        $event = $this->event(new \RuntimeException(
            'külső burok',
            0,
            new \RuntimeException('belső burok', 0, $this->settled()),
        ));

        $this->listener($executed)($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testItLogsTheSyncFailureEvent(): void
    {
        // A `catch (\Throwable)` ág önmagában nem bizonyítja, hogy a hiba
        // naplózva is lett — a naplózás mockolt elvárás nélkül némán is
        // eltűnhetne, miközben a vezérlésfolyam változatlan marad.
        $executed = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::isString(),
                self::callback(
                    static fn (array $context): bool => 'simplepay.capture.already_settled_sync_failed' === ($context['event'] ?? null),
                ),
            );

        $event = $this->event($this->settled());

        $this->listener($executed, syncThrows: new \RuntimeException('hálózati hiba'), logger: $logger)($event);
    }

    public function testItLogsWhenNoTokenCanBeResolved(): void
    {
        $executed = [];
        $verifier = $this->createStub(HttpRequestVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new NotFoundHttpException('nincs token'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::isString(),
                self::callback(
                    static fn (array $context): bool => 'simplepay.capture.already_settled_no_token' === ($context['event'] ?? null),
                ),
            );

        $event = $this->event($this->settled());

        $this->listener($executed, verifier: $verifier, logger: $logger)($event);
    }
}
