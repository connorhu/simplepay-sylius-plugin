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

    /**
     * A `getPath()`-nak NEM szabad felülírva lennie. A Symfony
     * `Bundle::getPath()` alapértelmezése a bundle-osztály saját könyvtárát
     * (`src/`) adja vissza — ez a fájlelrendezésünk valósága, hiszen a
     * `Resources/` a `src/` alatt van, nem a csomag gyökerénél.
     *
     * Ha valaki visszahozná a `\dirname(__DIR__)`-t visszaadó felülírást
     * (ami a csomag gyökerét adná), ez a teszt megbukik: a Twig
     * `TwigExtension::getBundleTemplatePaths()` a `$bundle['path'] .
     * '/Resources/views'` létezését nézi a `@Bundle` névtér
     * regisztrálásához, a `Kernel::locateResource()` pedig a
     * `$bundle->getPath() . '/' . $path` létezését a `@Bundle/...` route-
     * és config-importokhoz. A csomag gyökerénél egyik könyvtár sem
     * létezik — csak a `src/` alatt —, tehát egy rossz `getPath()` NÉMÁN
     * kiütné a plugin teljes admin-felületét (Twig-namespace) és a
     * routing-importot is, anélkül, hogy bármelyik meglévő teszt ezt
     * észrevenné.
     */
    public function testGetPathPointsAtTheDirectoryThatActuallyHoldsResources(): void
    {
        $path = new CodeConjureSyliusSimplePayPlugin()->getPath();

        self::assertDirectoryExists(
            $path . '/Resources/views',
            'A getPath() olyan könyvtárra kell mutasson, ami alatt a Resources/views '
            . 'ténylegesen létezik — enélkül a bundle @Name Twig-névtere sosem regisztrálódik.',
        );
        self::assertFileExists(
            $path . '/Resources/config/routing.yaml',
            'A getPath() olyan könyvtárra kell mutasson, ami alatt a Resources/config/routing.yaml '
            . 'ténylegesen létezik — enélkül a @Bundle/Resources/config/routing.yaml route-import '
            . '"Unable to find file" hibával elszáll.',
        );
    }
}
