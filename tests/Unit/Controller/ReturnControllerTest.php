<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Controller;

use CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController;
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

final class ReturnControllerTest extends TestCase
{
    private const string AFTER_URL = 'https://bolt.hu/rendeles/koszonjuk';

    /**
     * @param list<object> $executed
     *
     * A `$verifier` és az `$entityManager` NEM stub, hanem valódi elvárással
     * ellátott mock: a token invalidálása és a `flush()` elmaradása két
     * olyan hiba, amit egy `willReturn`-only stub némán elnyelne (a
     * konfigurálatlan hívás egyszerűen `null`-t adna vissza, a teszt pedig
     * észrevétlenül átfutna). Az `$logger` paraméter opcionális: csak azok a
     * tesztek adnak át valódi elvárással ellátott mockot, amelyeknek a
     * naplózás ténye számít — a többinek `NullLogger` elég. A többi dupla
     * itt csak válaszokat ad, valódi elvárás nélkül — azok maradnak stub-ok.
     */
    private function controller(
        array &$executed,
        ?\Throwable $syncThrows = null,
        ?LoggerInterface $logger = null,
    ): ReturnController {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn(self::AFTER_URL);
        $token->method('getGatewayName')->willReturn('simplepay');

        $verifier = $this->createMock(HttpRequestVerifierInterface::class);
        $verifier->method('verify')->willReturn($token);
        $verifier->expects(self::never())->method('invalidate');

        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use (&$executed, $syncThrows): void {
                $executed[] = $request;

                if ($request instanceof Sync && null !== $syncThrows) {
                    throw $syncThrows;
                }

                if ($request instanceof GetHumanStatus) {
                    $request->markCaptured();
                }
            },
        );

        $payum = $this->createStub(Payum::class);
        $payum->method('getHttpRequestVerifier')->willReturn($verifier);
        $payum->method('getGateway')->willReturn($gateway);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        return new ReturnController($payum, $entityManager, $logger ?? new NullLogger());
    }

    private function request(string $query = 'payum_token=abc&e=success&r=cmF3&s=c2ln'): Request
    {
        return Request::create('/payment/simplepay/return?' . $query);
    }

    public function testItSyncsBeforeItReadsTheStatus(): void
    {
        $executed = [];

        $this->controller($executed)($this->request());

        self::assertCount(2, $executed, 'Csak a Sync és a GetHumanStatus futhat, más nem.');
        self::assertInstanceOf(Sync::class, $executed[0]);
        self::assertInstanceOf(GetHumanStatus::class, $executed[1]);
    }

    public function testItRedirectsToTheTokenAfterUrlSoSyliusRendersItsOwnPage(): void
    {
        $executed = [];

        $response = $this->controller($executed)($this->request());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AFTER_URL, $response->getTargetUrl());
    }

    public function testAFailedSyncStillRedirectsTheCustomerRatherThanShowingAnError(): void
    {
        // A vevő böngészője nem a hibakezelés helye. Ha a lekérdezés nem megy
        // át, az IPN úgyis megérkezik; a vevőt a Sylius szokásos oldalára
        // engedjük, és a hibát naplózzuk.
        $executed = [];

        $response = $this->controller($executed, new \RuntimeException('hálózati hiba'))($this->request());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AFTER_URL, $response->getTargetUrl());
    }

    public function testAMissingReturnPayloadDoesNotBreakThePage(): void
    {
        // Az r/s hiánya nem hiba: tájékoztató adat, ami sosem dönt. A Sync-nek
        // AKKOR IS le kell futnia, ha nincs r/s — a böngészőn átjött payload
        // jelenléte NEM kapcsolhatja ki a valódi állapot-lekérdezést.
        $executed = [];

        $response = $this->controller($executed)($this->request('payum_token=abc'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertCount(2, $executed, 'A Sync és a GetHumanStatus akkor is le kell fusson, ha nincs r/s.');
        self::assertInstanceOf(Sync::class, $executed[0]);
    }

    public function testItNeverDecidesTheStateFromTheReturnPayload(): void
    {
        // Az r/e paraméter azt mondja, mit LÁT a vásárló — nem azt, hogy a
        // pénz megérkezett-e. Az állapotot a Sync és a GetHumanStatus dönti
        // el, `e=fail` mellett is: egyik gateway-hívás sem maradhat el, és a
        // sorrend sem változhat attól, hogy a böngésző mit üzent.
        $executed = [];

        $this->controller($executed)($this->request('payum_token=abc&e=fail&r=cmF3&s=c2ln'));

        self::assertCount(2, $executed);
        self::assertInstanceOf(Sync::class, $executed[0]);
        self::assertInstanceOf(GetHumanStatus::class, $executed[1]);
    }

    public function testItLogsThatThePayloadWasReceivedWhenRAndSArePresent(): void
    {
        // A spec `parseReturn()`-je helyett a terv csak az r/s jelenlétét
        // naplózza (lásd a controller docblockját). Ez az egyszerűsítés
        // csak akkor nem veszít információt, ha ez a naplózás ténylegesen
        // megtörténik — ezt itt pin-eljük le.
        $executed = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                self::isString(),
                self::callback(
                    static fn (array $context): bool => 'simplepay.return.received' === ($context['event'] ?? null),
                ),
            );

        $this->controller($executed, logger: $logger)($this->request());
    }

    public function testItLogsThatThePayloadWasMissingWhenRIsAbsent(): void
    {
        $executed = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                self::isString(),
                self::callback(
                    static fn (array $context): bool => 'simplepay.return.no_payload' === ($context['event'] ?? null),
                ),
            );

        $this->controller($executed, logger: $logger)($this->request('payum_token=abc'));
    }
}
