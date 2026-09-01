<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Integration;

use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockHttpClient;
use Payum\Bundle\PayumBundle\ReplyToSymfonyResponseConverter;
use Payum\Core\Payum;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\RouterInterface;

/**
 * A `services.xml` és a konstruktorok együttes ellenőrzése.
 *
 * A `ServiceDefinitionTest` csak azt nézi, MI van deklarálva. Ez a teszt
 * lefordítja a konténert és példányosít: itt bukik ki az a fajta hiba, ami a
 * Payum-csomagban a bundle bootolását akadályozta meg — a `services.xml`
 * `on-invalid="null"`-t ígért, a konstruktor nem-nullable-t követelt.
 */
final class ContainerCompilationTest extends TestCase
{
    /**
     * Minden szolgáltatás, amit a plugin a befogadó alkalmazástól vár.
     * A `logger` szándékosan NINCS a listán: azt esetenként adjuk hozzá.
     *
     * @var array<string, class-string>
     */
    private const array EXTERNAL_SERVICES = [
        'router' => RouterInterface::class,
        'payum' => Payum::class,
        'doctrine.orm.entity_manager' => EntityManagerInterface::class,
        'payum.reply_to_symfony_response_converter' => ReplyToSymfonyResponseConverter::class,
        'sylius.repository.payment_method' => PaymentMethodRepositoryInterface::class,
        'sylius.repository.payment' => PaymentRepositoryInterface::class,
        'sylius.repository.order' => OrderRepositoryInterface::class,
        'Psr\Http\Client\ClientInterface' => ClientInterface::class,
    ];

    /**
     * A `Psr\Http\Client\ClientInterface` a többi külső szolgáltatástól
     * eltérően NEM lehet szintetikus: a `DecoratorServicePass` fordítási
     * hibával (`A synthetic service cannot be decorated`) utasítja el, ha a
     * dekorált szolgáltatás szintetikus. Ezért ezt az egyet egy valódi,
     * nulla kötelező paraméterrel példányosítható PSR-18 implementációval
     * (`Http\Mock\Client`) helyettesítjük — ez nem a plugin, hanem a teszt
     * korlátja, a services.xml dekorálása emiatt nem változik.
     */
    private const string DECORATED_INTERFACE_ID = 'Psr\Http\Client\ClientInterface';

    private function compiledContainer(bool $withLogger): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        foreach (self::EXTERNAL_SERVICES as $id => $class) {
            if (self::DECORATED_INTERFACE_ID === $id) {
                $container->register($id, MockHttpClient::class)->setPublic(true);

                continue;
            }

            $container->register($id, $class)->setSynthetic(true)->setPublic(true);
        }

        if ($withLogger) {
            $container->register('logger', NullLogger::class)->setPublic(true);
        }

        new CodeConjureSyliusSimplePayExtension()->load([], $container);

        // A plugin szolgáltatásai éles konténerben privátak; itt publikussá
        // tesszük őket, hogy a get() ténylegesen példányosítson.
        foreach ($this->pluginServiceIds($container) as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        $container->compile();

        foreach (self::EXTERNAL_SERVICES as $id => $class) {
            if (self::DECORATED_INTERFACE_ID === $id) {
                // Valódi osztály — a konténer maga példányosítja, nincs
                // szükség szintetikus set()-re.
                continue;
            }

            $container->set($id, $this->createStub($class));
        }

        return $container;
    }

    /**
     * @return list<class-string>
     */
    private function pluginServiceIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach (array_keys($container->getDefinitions()) as $id) {
            if (str_starts_with($id, 'CodeConjure\\SyliusSimplePayPlugin\\') && class_exists($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function testEveryServiceCanBeInstantiatedWhenTheApplicationProvidesEverything(): void
    {
        $container = $this->compiledContainer(withLogger: true);
        $ids = $this->pluginServiceIds($container);

        self::assertNotSame([], $ids, 'A kiterjesztés egyetlen szolgáltatást sem töltött be.');

        foreach ($ids as $id) {
            self::assertInstanceOf($id, $container->get($id), sprintf('A(z) %s nem példányosítható.', $id));
        }
    }

    public function testTheBundleStillWorksWithoutALoggerService(): void
    {
        // A services.xml on-invalid="null"-t ígér a loggerre. Ha egy
        // konstruktor nem-nullable naplózót követel, ez a teszt bukik — a
        // Payum-csomagban pontosan ez akadályozta meg a bundle bootolását.
        $container = $this->compiledContainer(withLogger: false);

        self::assertFalse($container->has('logger'));

        foreach ($this->pluginServiceIds($container) as $id) {
            self::assertInstanceOf($id, $container->get($id), sprintf('A(z) %s naplózó nélkül nem példányosítható.', $id));
        }
    }

    public function testTheRecordingClientReallyWrapsTheApplicationsClient(): void
    {
        // A dekorálás a "record" kapcsoló működésének a feltétele: a
        // gateway a konténerből kapja a klienst, és ha nem a rögzítőt kapja,
        // a kapcsoló némán nem csinál semmit.
        $container = $this->compiledContainer(withLogger: true);

        self::assertInstanceOf(
            RecordingHttpClient::class,
            $container->get(RecordingHttpClient::class),
        );

        // A dekorálás bizonyítéka az az alias, amit a DecoratorServicePass
        // hoz létre: a `Psr\Http\Client\ClientInterface` mostantól a
        // rögzítőre mutat. (A ténylegesen becsomagolt szolgáltatást a
        // `<azonosító>.inner` néven kapná meg a rögzítő konstruktora, de ez
        // egy magánjellegű, egyszer hivatkozott szolgáltatás, amit a
        // ContainerBuilder a fordítás során beágyaz a rögzítő argumentumába
        // — emiatt nem kereshető külön `has()`-sal, csak az alias jelzi
        // megbízhatóan, hogy a decorates= tényleg lefutott.)
        self::assertTrue(
            $container->hasAlias(self::DECORATED_INTERFACE_ID),
            'Nem jött létre alias a dekorált szolgáltatásra — a dekorálás nem történt meg.',
        );
        self::assertSame(
            RecordingHttpClient::class,
            (string) $container->getAlias(self::DECORATED_INTERFACE_ID),
            'Az alias nem a rögzítő szolgáltatásra mutat.',
        );
    }
}
