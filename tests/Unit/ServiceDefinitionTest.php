<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Checkout\PaymentAlreadySettledListener;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use CodeConjure\SyliusSimplePayPlugin\Extension\ForceReconvertOnDeadTransactionExtension;
use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use CodeConjure\SyliusSimplePayPlugin\Twig\SimplePayExtension;
use Payum\Core\Extension\Context;
use Payum\Core\GatewayInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServiceDefinitionTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        new CodeConjureSyliusSimplePayExtension()->load([], $container);

        return $container;
    }

    /** @return iterable<string, array{class-string}> */
    public static function services(): iterable
    {
        yield 'Convert action' => [ConvertPaymentAction::class];
        yield 'admin űrlap' => [SimplePayGatewayConfigurationType::class];
        yield 'IPN controller' => [IpnController::class];
        yield 'visszatérési controller' => [ReturnController::class];
        yield 'refund parancs' => [RefundCommand::class];
        yield 'Twig extension' => [SimplePayExtension::class];
        yield 'lezárult fizetés figyelő' => [PaymentAlreadySettledListener::class];
        yield 'rögzítő HTTP kliens' => [RecordingHttpClient::class];
        yield 'halott tranzakció újramintázó kiterjesztés' => [ForceReconvertOnDeadTransactionExtension::class];
    }

    /** @param class-string $class */
    #[DataProvider('services')]
    public function testEveryPublicPieceIsRegistered(string $class): void
    {
        self::assertTrue(
            $this->container()->hasDefinition($class),
            sprintf('A(z) %s nincs bekötve a services.xml-be.', $class),
        );
    }

    public function testTheConvertActionIsTaggedForTheSimplepayGatewayOnly(): void
    {
        $tags = $this->container()->getDefinition(ConvertPaymentAction::class)->getTag('payum.action');

        self::assertCount(1, $tags);
        self::assertSame('simplepay', $tags[0]['factory'] ?? null);
    }

    /**
     * R32 (2. találat) regresszió-védelem: az átvizsgálás három olyan
     * mutációt talált, ami az összes tesztet zölden hagyta —
     *   1. a `<service>` blokk törlése a services.xml-ből (→ innen a
     *      `testEveryPublicPieceIsRegistered` bukik, mert a kiterjesztés
     *      szerepel a `services()` listában),
     *   2. a `factory="simplepay"` cseréje `all="true"`-ra (→ EZT a
     *      tesztet bukja),
     *   3. a hívó-kontextus őr `if (false)`-ra cserélése (→ a lentebbi
     *      `testTheForceReconvertExtensionNeverActsOnATopLevelGetStatus`
     *      bukik, mert a kiterjesztés ilyenkor egy verszintű GetStatus-t
     *      is átírna).
     *
     * A `ConvertPaymentAction` fenti tesztje ugyanezt a formát követi a
     * `payum.action` tagre — ez a `payum.extension` tag megfelelője.
     */
    public function testTheForceReconvertExtensionIsTaggedForTheSimplepayGatewayOnly(): void
    {
        $tags = $this->container()
            ->getDefinition(ForceReconvertOnDeadTransactionExtension::class)
            ->getTag('payum.extension');

        self::assertCount(1, $tags);
        self::assertSame('simplepay', $tags[0]['factory'] ?? null);
    }

    /**
     * A hívó-kontextus szűkítés viselkedési bizonyítéka (nem csak a DI-
     * bekötésé): a visszatérési controller és a `PaymentAlreadySettled-
     * Listener` a `Sync`/`GetHumanStatus` úton halad, de EZEK UTÁN a
     * Sylius saját `UpdatePaymentStateExtension`-je egy VERSZINTŰ (nem
     * beágyazott) `GetStatus`-t indít a saját `onPostExecute()`-jában
     * (`$context->getGateway()->execute($status = new GetStatus($payment))`,
     * `vendor/sylius/sylius/.../Extension/UpdatePaymentStateExtension.php:76`),
     * hogy frissítse a Sylius `Payment` állapotát a VALÓDI tárolt
     * státuszra. Ha a mi kiterjesztésünk erre a verszintű hívásra is
     * reagálna, egy halott státuszt PONT itt írna át `NEW`-ra — ez a
     * teszt bizonyítja, hogy a hatókör-szűkítés (`wasCalledFrom-
     * CapturePaymentAction()`) ezt megakadályozza: üres `getPrevious()`
     * (verszintű hívás) esetén a kiterjesztés érintetlenül hagyja a
     * kérést.
     */
    public function testTheForceReconvertExtensionNeverActsOnATopLevelGetStatus(): void
    {
        $model = new \ArrayObject([
            Details::STATE_KEY => ['status' => TransactionStatus::Timeout->value, 'attempt' => 1],
        ]);

        $request = new GetStatus($model);
        $request->markCanceled();

        $gateway = $this->createStub(GatewayInterface::class);
        // ÜRES előzmény-verem — pontosan ez különbözteti meg a verszintű
        // hívást a `CapturePaymentAction` által indított beágyazottól.
        $context = new Context($gateway, $request, []);

        new ForceReconvertOnDeadTransactionExtension()->onPostExecute($context);

        self::assertFalse($request->isNew(), 'A verszintű GetStatus-t sosem szabad NEW-ra írni.');
        self::assertTrue($request->isCanceled(), 'A meglévő jelölésnek változatlannak kell maradnia.');
    }

    public function testTheGatewayConfigurationTypeCarriesTheSyliusTag(): void
    {
        $tags = $this->container()
            ->getDefinition(SimplePayGatewayConfigurationType::class)
            ->getTag('sylius.gateway_configuration_type');

        self::assertCount(1, $tags);
        self::assertSame('simplepay', $tags[0]['type'] ?? null);
        self::assertSame('SimplePay', $tags[0]['label'] ?? null);
    }

    public function testTheControllersArePublicBecauseTheRouterResolvesThem(): void
    {
        $container = $this->container();

        self::assertTrue($container->getDefinition(IpnController::class)->isPublic());
        self::assertTrue($container->getDefinition(ReturnController::class)->isPublic());
    }

    public function testTheRecordingClientDecoratesTheApplicationsHttpClient(): void
    {
        // Enélkül a --record néma no-op: a gateway a dekorálatlan klienst
        // kapná, és a RefundCommand egy forgalom nélküli példányt kapcsolna be.
        $decorated = $this->container()
            ->getDefinition(RecordingHttpClient::class)
            ->getDecoratedService();

        self::assertNotNull($decorated, 'A rögzítő kliens nem dekorál semmit.');
        self::assertSame('Psr\Http\Client\ClientInterface', $decorated[0]);
    }

    public function testTheSettledListenerIsRegisteredForTheKernelExceptionEvent(): void
    {
        $tags = $this->container()
            ->getDefinition(PaymentAlreadySettledListener::class)
            ->getTag('kernel.event_listener');

        self::assertCount(1, $tags);
        self::assertSame('kernel.exception', $tags[0]['event'] ?? null);
    }
}
