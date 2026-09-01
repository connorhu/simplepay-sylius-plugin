<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit;

use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Checkout\PaymentAlreadySettledListener;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use CodeConjure\SyliusSimplePayPlugin\Twig\SimplePayExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
