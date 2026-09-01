<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Resources\Config;

use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * A `routing.yaml` a Task 7 admin sablonjának SZERZŐDÉSE: a sablon
 * `url('codeconjure_simplepay_ipn', {'code': payment_method_code})`-ot hív,
 * betű szerint. Ha ez a fájl felcserélné a két route nevét vagy útvonalát,
 * vagy elgépelné a `code` paramétert, egyetlen eddigi teszt sem venné
 * észre — az `IpnControllerTest` a kontrollert közvetlenül példányosítja,
 * a routing fájlt sosem tölti be.
 *
 * Ez a teszt a fájlt magát olvassa be, a valódi Symfony YAML
 * route-betöltővel — nem a konténer-fordítást ellenőrzi (az a Task 13
 * dolga), hanem azt, hogy a fájl ténylegesen azt mondja, amitől a Task 7
 * sablonja függ.
 */
final class RoutingTest extends TestCase
{
    private function routes(): RouteCollection
    {
        $configDir = \dirname(__DIR__, 4) . '/src/Resources/config';

        $loader = new YamlFileLoader(new FileLocator([$configDir]));

        return $loader->load('routing.yaml');
    }

    public function testTheIpnRouteMatchesTheContractTheAdminTemplateDependsOn(): void
    {
        $route = $this->routes()->get('codeconjure_simplepay_ipn');

        self::assertNotNull($route, 'A "codeconjure_simplepay_ipn" route-nak léteznie kell.');
        self::assertSame('/payment/simplepay/ipn/{code}', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(IpnController::class, $route->getDefault('_controller'));
        self::assertSame(
            ['code'],
            $route->compile()->getPathVariables(),
            'A route paraméterének "code"-nak kell lennie — a Task 7 sablonja erre a névre hivatkozik.',
        );
    }

    public function testTheReturnRouteExistsWithTheContractTask9WillRelyOn(): void
    {
        $route = $this->routes()->get('codeconjure_simplepay_return');

        self::assertNotNull($route, 'A "codeconjure_simplepay_return" route-nak léteznie kell.');
        self::assertSame('/payment/simplepay/return', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        // A `ReturnController`-t a Task 9 hozza létre — itt még nem
        // létezik, ezért NEM `::class`-szal hivatkozunk rá (azt a PHPStan
        // `class.notFound`-ként jelezné), hanem az elvárt, teljesen
        // minősített osztálynévvel mint nyers stringgel.
        self::assertSame(
            'CodeConjure\\SyliusSimplePayPlugin\\Controller\\ReturnController',
            $route->getDefault('_controller'),
        );
    }
}
