<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit;

use CodeConjure\SyliusSimplePayPlugin\CodeConjureSyliusSimplePayPlugin;
use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PluginTest extends TestCase
{
    public function testTheExtensionLoadsTheServiceDefinitionsWithoutError(): void
    {
        $container = new ContainerBuilder();

        new CodeConjureSyliusSimplePayExtension()->load([], $container);

        $this->expectNotToPerformAssertions();
    }

    public function testThePluginExposesItsOwnExtension(): void
    {
        self::assertInstanceOf(
            CodeConjureSyliusSimplePayExtension::class,
            new CodeConjureSyliusSimplePayPlugin()->getContainerExtension(),
        );
    }
}
