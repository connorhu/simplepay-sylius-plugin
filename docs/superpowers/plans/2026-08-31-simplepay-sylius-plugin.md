# `codeconjure/simplepay-sylius-plugin` implementációs terv

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sylius plugin a SimplePay v2 fizetéshez: admin gateway űrlap, `Payment` → SimplePay payload leképezés, IPN-végpont, visszatérési végpont, jóváírás konzol parancs — majd a régi implementáció eltakarítása a boltból.

**Architecture:** A plugin a `codeconjure/simplepay-payum` csomag `details` szerződését tölti ki. A `ConvertPaymentAction` a Sylius `Payment`-ből `simplepay_request` tömböt épít; két saját controller viszi be az IPN-t és a visszatérést a Payum gateway-be. Az állapotot mindig a `Sync` (`/query`) vagy az IPN dönti el, sosem a böngészőn átjött `r`/`s` adat.

**Tech Stack:** PHP 8.4, Sylius 2.1, Symfony 7.2, `codeconjure/simplepay-payum`, `codeconjure/simplepay`. Teszt: PHPUnit 12, PHPStan level 9, ECS (sylius-labs).

**Spec:** `docs/superpowers/specs/2026-08-31-simplepay-sylius-plugin-design.md`

**Előfeltétel:** a `codeconjure/simplepay-payum` csomag terve (`incubator/simplepay-payum/docs/superpowers/plans/2026-08-31-simplepay-payum.md`) végig van hajtva, és a csomag zölden fut.

## Global Constraints

- PHP `^8.4`. Minden fájl `declare(strict_types=1);`-gyel kezdődik.
- Namespace: `CodeConjure\SyliusSimplePayPlugin\`, tesztek: `CodeConjure\SyliusSimplePayPlugin\Tests\`.
- **Minden osztály `final`**, az adathordozók `readonly`.
- **Szigorú összehasonlítás mindenhol** (`===`, `!==`). A `"0"` és a `0` mindig jogos érték, sosem üresség.
- **Nincs néma degradáció:** hiányzó kötelező adatra nincs alapértelmezés, nincs kerekítés, nincs olyan kapcsoló, aminek az eredményét eldobjuk.
- **A protokoll-csomaghoz (`codeconjure/simplepay`) nem nyúlunk.**
- **A Payum-csomag `details` kulcsait mindig a `Details::*_KEY` konstansokon keresztül írjuk**, sosem nyers stringként.
- **Az `r`/`s` visszatérési adat sosem dönt állapotot** — csak naplózódik és keresztellenőrződik.
- **A boltba (`/server/www/egyhazzene.hu/bolt`) csak a 15. taskban írunk.** Addig a plugin önállóan készül.
- **A GPL-es hivatalos SDK-ból kódot másolni tilos.**
- Magyar commit üzenetek, Conventional Commits előtaggal.
- Minden task végén zöld: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse -c phpstan.dist.neon`, `vendor/bin/ecs check`.

## Fájlszerkezet

| Fájl | Felelősség |
|---|---|
| `src/Money/SyliusAmountConverter.php` | Sylius kétszázados összeg → a pénznem valódi alegysége |
| `src/Order/OrderReference.php` | az `orderRef` séma: előállítás és visszafejtés egy helyen |
| `src/Language/LocaleToLanguageMap.php` | Sylius locale → SimplePay fizetőoldal-nyelv |
| `src/Gateway/GatewayConfigReader.php` | a Sylius gateway config tipizált olvasása |
| `src/Action/ConvertPaymentAction.php` | Sylius `Payment` → `simplepay_request` |
| `src/Form/Type/SimplePayGatewayConfigurationType.php` | admin űrlap |
| `src/Controller/IpnController.php` | tokenmentes IPN-végpont |
| `src/Controller/ReturnController.php` | visszatérés a fizetőoldalról |
| `src/Command/RefundCommand.php` | konzolos jóváírás |
| `src/Debug/RecordingHttpClient.php` | nyers HTTP-forgalom rögzítése méréshez |
| `src/View/SimplePayPaymentView.php` | admin read-model a `simplepay_state`-ből |
| `src/Resources/**` | szolgáltatások, útvonalak, sablonok, fordítások |

## Kivétel-készlet

Egyetlen interfész és három konkrét kivétel; a Task 2 hozza létre őket, a többi task használja.

| Kivétel | Mikor |
|---|---|
| `SyliusSimplePayException` (interfész) | mind implementálja |
| `UnrepresentableAmountException` (`\RuntimeException`) | a Sylius összeg nem ábrázolható a pénznem alegységében |
| `IncompletePaymentException` (`\RuntimeException`) | hiányzik a rendelés, a vevő, a cím vagy a locale |
| `GatewayMismatchException` (`\RuntimeException`) | a fizetés pénzneme nem a merchanté, vagy a payment method nem `simplepay` |

---

### Task 1: Csomag-alap

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `phpstan.dist.neon`, `ecs.php`, `.gitignore`, `.github/workflows/ci.yaml`, `src/CodeConjureSyliusSimplePayPlugin.php`, `src/DependencyInjection/CodeConjureSyliusSimplePayExtension.php`, `src/Resources/config/services.xml`
- Test: `tests/Unit/PluginTest.php`

**Interfaces:**
- Consumes: semmit
- Produces: betölthető Symfony bundle, futtatható eszközlánc

- [ ] **Step 1: `composer.json`**

```json
{
    "name": "codeconjure/simplepay-sylius-plugin",
    "description": "Sylius plugin for OTP SimplePay v2 payments",
    "type": "sylius-plugin",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "codeconjure/simplepay": "^1.0",
        "codeconjure/simplepay-payum": "^1.0",
        "nyholm/psr7": "^1.8",
        "sylius/sylius": "^2.1",
        "symfony/http-client": "^7.2"
    },
    "require-dev": {
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^12.0",
        "sylius-labs/coding-standard": "^4.4"
    },
    "autoload": {
        "psr-4": {
            "CodeConjure\\SyliusSimplePayPlugin\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CodeConjure\\SyliusSimplePayPlugin\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "php-http/discovery": false,
            "symfony/flex": false,
            "symfony/thanks": false
        }
    },
    "minimum-stability": "stable"
}
```

> **A `sylius/sylius` dev-függőségként is elég volna** (a plugin nem futtat
> Sylius kernelt tesztben), de éles használatban valódi függőség: a plugin
> Sylius interfészekre típusoz. Marad a `require`-ben.
>
> **A `codeconjure/simplepay-payum` feloldásáról:** amíg egyik csomag sincs a
> Packagiston, add hozzá helyben, a `composer.json` érintése nélkül:
> ```bash
> composer config repositories.simplepay path ../simplepay
> composer config repositories.simplepay-payum path ../simplepay-payum
> ```
> A Task 14 zárja le, hogy a commitolt `composer.json`-ba VCS repository
> kerüljön (vagy semmi, ha addigra fent vannak a Packagiston).

- [ ] **Step 2: Eszköz-konfigurációk**

`phpstan.dist.neon`:

```neon
parameters:
    level: 9
    paths:
        - src
        - tests
```

`ecs.php`:

```php
<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function (ECSConfig $config): void {
    $config->import('vendor/sylius-labs/coding-standard/ecs.php');
    $config->paths([__DIR__ . '/src', __DIR__ . '/tests']);
};
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnNotice="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`.gitignore`:

```
/vendor/
/composer.lock
/.phpunit.cache/
```

`.github/workflows/ci.yaml` — azonos a `simplepay-payum` csomagéval:

```yaml
name: CI

on:
    push: ~
    pull_request: ~
    workflow_dispatch: ~

permissions:
    contents: read

jobs:
    checks:
        name: "Tesztek és statikus ellenőrzés (PHP ${{ matrix.php }})"
        runs-on: ubuntu-latest
        strategy:
            fail-fast: false
            matrix:
                php: ['8.4']
        steps:
            - uses: actions/checkout@v4

            - uses: shivammathur/setup-php@v2
              with:
                  php-version: ${{ matrix.php }}
                  coverage: none

            - uses: ramsey/composer-install@v3

            - name: Unit tesztek
              run: vendor/bin/phpunit

            - name: PHPStan
              run: vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress

            - name: ECS
              run: vendor/bin/ecs check --no-progress-bar
```

> A PHP 8.5-öt itt kihagyjuk a mátrixból: a Sylius 2.1 még nem hirdet 8.5
> támogatást, és egy piros CI, aminek az oka a keretrendszer, elfedi a
> valódi hibákat.

- [ ] **Step 3: A bundle megírása**

`src/CodeConjureSyliusSimplePayPlugin.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin;

use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CodeConjureSyliusSimplePayPlugin extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new CodeConjureSyliusSimplePayExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
```

`src/DependencyInjection/CodeConjureSyliusSimplePayExtension.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class CodeConjureSyliusSimplePayExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'))
            ->load('services.xml');
    }

    public function getAlias(): string
    {
        return 'codeconjure_sylius_simple_pay';
    }
}
```

`src/Resources/config/services.xml` — egyelőre üres váz; minden task
hozzáadja a magáét:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <defaults autowire="false" autoconfigure="false" public="false"/>
    </services>
</container>
```

- [ ] **Step 4: A teszt megírása és futtatása**

`tests/Unit/PluginTest.php`:

```php
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

        self::assertTrue(true, 'A services.xml betöltése nem dobhat kivételt.');
    }

    public function testThePluginExposesItsOwnExtension(): void
    {
        self::assertInstanceOf(
            CodeConjureSyliusSimplePayExtension::class,
            new CodeConjureSyliusSimplePayPlugin()->getContainerExtension(),
        );
    }
}
```

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: csomag-alap, bundle váz és eszközök

PHP 8.4 minimum, phpstan level 9, ECS, PHPUnit 12. A services.xml egyelőre
üres váz; minden további task a sajátját adja hozzá."
```

---

### Task 2: Kivételek és `Money\SyliusAmountConverter`

**Files:**
- Create: `src/Exception/SyliusSimplePayException.php`, `UnrepresentableAmountException.php`, `IncompletePaymentException.php`, `GatewayMismatchException.php`, `src/Money/SyliusAmountConverter.php`
- Test: `tests/Unit/Money/SyliusAmountConverterTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\Currency`
- Produces:
  - `SyliusSimplePayException` interfész + három konkrét kivétel
  - `SyliusAmountConverter::toMinorUnits(int $syliusAmount, Currency $currency): int`
  - `SyliusAmountConverter::toSyliusAmount(int $minorUnits, Currency $currency): int`

**Ez a csomag legkényesebb osztálya.** A Sylius pénznemtől függetlenül 1/100
egységben tárol; a `Money::fromMinorUnits()` a pénznem valódi kitevője szerinti
alegységet várja. A régi implementáció minden pénznemre két tizedessel osztott
100-zal, ami HUF-nál **százszoros eltérés** lett volna.

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Money/SyliusAmountConverterTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Money;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyliusAmountConverterTest extends TestCase
{
    /** @return iterable<string, array{int, Currency, int}> */
    public static function amounts(): iterable
    {
        yield '1000 Ft' => [100000, Currency::HUF, 1000];
        yield '1 Ft' => [100, Currency::HUF, 1];
        yield 'nulla forint' => [0, Currency::HUF, 0];
        yield 'negatív forint (jóváírásnál előfordul)' => [-50000, Currency::HUF, -500];
        yield '10 euró' => [1000, Currency::EUR, 1000];
        yield '1 eurocent' => [1, Currency::EUR, 1];
        yield 'nulla euró' => [0, Currency::EUR, 0];
        yield '10 dollár' => [1000, Currency::USD, 1000];
    }

    #[DataProvider('amounts')]
    public function testItConvertsToTheCurrencyMinorUnit(
        int $syliusAmount,
        Currency $currency,
        int $expected,
    ): void {
        self::assertSame($expected, SyliusAmountConverter::toMinorUnits($syliusAmount, $currency));
    }

    #[DataProvider('amounts')]
    public function testTheConversionRoundTrips(
        int $syliusAmount,
        Currency $currency,
        int $minorUnits,
    ): void {
        self::assertSame($syliusAmount, SyliusAmountConverter::toSyliusAmount($minorUnits, $currency));
    }

    public function testAForintAmountWithFractionalPartIsLoudNotRounded(): void
    {
        // 100050 Sylius-egység = 1000,50 Ft. A SimplePay HUF-nál egész
        // forintot vár; a csendes kerekítés pénzügyi hiba volna.
        $this->expectException(UnrepresentableAmountException::class);
        $this->expectExceptionMessage('100050');

        SyliusAmountConverter::toMinorUnits(100050, Currency::HUF);
    }

    public function testTheExceptionNamesTheCurrencySoTheCauseIsObvious(): void
    {
        $this->expectException(UnrepresentableAmountException::class);
        $this->expectExceptionMessage('HUF');

        SyliusAmountConverter::toMinorUnits(1, Currency::HUF);
    }

    public function testAEuroAmountNeverNeedsRoundingBecauseTheExponentsMatch(): void
    {
        // EUR-nál a Sylius ábrázolás és a pénznem alegysége egybeesik,
        // tehát semmilyen érték nem lehet ábrázolhatatlan.
        self::assertSame(1, SyliusAmountConverter::toMinorUnits(1, Currency::EUR));
        self::assertSame(99, SyliusAmountConverter::toMinorUnits(99, Currency::EUR));
    }

    public function testANegativeForintWithFractionalPartIsAlsoRejected(): void
    {
        $this->expectException(UnrepresentableAmountException::class);

        SyliusAmountConverter::toMinorUnits(-100050, Currency::HUF);
    }
}
```

> A `testANegativeForintWithFractionalPartIsAlsoRejected` nem formaság: a
> PHP `%` operátora negatív osztandóra negatív maradékot ad (`-100050 % 100`
> `-50`), tehát a `0 !== $amount % $divisor` ellenőrzés helyesen működik —
> de egy `abs()` nélküli, `>` alapú ellenőrzés elrontaná. A teszt ezt őrzi.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Money/SyliusAmountConverterTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter" not found`.

- [ ] **Step 3: A kivételek és az átváltó megírása**

`src/Exception/SyliusSimplePayException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/** A plugin minden kivételének közös interfésze — egyetlen `catch` mindet elkapja. */
interface SyliusSimplePayException extends \Throwable
{
}
```

`src/Exception/UnrepresentableAmountException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A Sylius összeg nem ábrázolható a pénznem valódi alegységében.
 *
 * A Sylius pénznemtől függetlenül 1/100 egységben tárol, tehát HUF esetén
 * elő tud állni olyan érték, ami tört forintot jelentene. A SimplePay egész
 * forintot vár. A csendes kerekítés itt pénzügyi hiba volna, ezért hangos.
 */
final class UnrepresentableAmountException extends \RuntimeException implements SyliusSimplePayException
{
}
```

`src/Exception/IncompletePaymentException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A fizetésből hiányzik egy adat, ami nélkül a SimplePay kérés nem
 * állítható össze: rendelés, vevő e-mail, számlázási cím vagy locale.
 *
 * Nincs helyettesítő érték. Egy üres e-mail címmel elküldött kérés vagy
 * elbukik a SimplePay-nél, vagy — rosszabb esetben — átmegy, és a vevő
 * nem kap visszaigazolást.
 */
final class IncompletePaymentException extends \RuntimeException implements SyliusSimplePayException
{
}
```

`src/Exception/GatewayMismatchException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A fizetés és a gateway nem illik össze: eltérő pénznem, vagy a payment
 * method nem a `simplepay` factory-t használja.
 *
 * A pénznem-eltérés valódi eset: a SimplePay merchant azonosító pénznemhez
 * kötött, egy merchant egy pénznemet fogad. Enélkül az ellenőrzés nélkül a
 * kérés kimenne, és a SimplePay egy nehezen értelmezhető hibakóddal
 * utasítaná el.
 */
final class GatewayMismatchException extends \RuntimeException implements SyliusSimplePayException
{
}
```

`src/Money/SyliusAmountConverter.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Money;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;

/**
 * Sylius összeg ↔ a pénznem valódi alegysége.
 *
 * A Sylius PÉNZNEMTŐL FÜGGETLENÜL 1/100 egységben tárol: 1000 Ft → 100000,
 * 10 EUR → 1000. A `Money::fromMinorUnits()` viszont a pénznem valódi
 * kitevője szerinti alegységet várja: HUF-nál egész forintot, EUR-nál centet.
 *
 * | pénznem | kitevő | Sylius | osztó | SimplePay      |
 * |---------|--------|--------|-------|----------------|
 * | HUF     | 0      | 100000 | 100   | 1000 (forint)  |
 * | EUR     | 2      | 1000   | 1     | 1000 (cent)    |
 * | USD     | 2      | 1000   | 1     | 1000 (cent)    |
 *
 * SOHA NEM KEREKÍT. A régi implementáció minden pénznemre két tizedessel
 * osztott 100-zal — HUF-nál ez százszoros eltérés lett volna.
 */
final class SyliusAmountConverter
{
    public static function toMinorUnits(int $syliusAmount, Currency $currency): int
    {
        $divisor = self::divisor($currency);

        if (0 !== $syliusAmount % $divisor) {
            throw new UnrepresentableAmountException(sprintf(
                'A(z) %d Sylius-egység nem ábrázolható %s alegységben: a %s legkisebb '
                . 'egysége %d Sylius-egységnek felel meg, és a kerekítés pénzügyi hiba volna.',
                $syliusAmount,
                $currency->value,
                $currency->value,
                $divisor,
            ));
        }

        return intdiv($syliusAmount, $divisor);
    }

    public static function toSyliusAmount(int $minorUnits, Currency $currency): int
    {
        return $minorUnits * self::divisor($currency);
    }

    /**
     * A Sylius mindig két tizedessel ábrázol; az osztó a különbség a Sylius
     * rögzített kitevője (2) és a pénznem valódi kitevője között.
     */
    private static function divisor(Currency $currency): int
    {
        return 10 ** (2 - $currency->exponent());
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Money/SyliusAmountConverterTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Exception src/Money tests/Unit/Money
git commit -m "feat(money): Sylius összeg átváltása a pénznem valódi alegységére

A Sylius pénznemtől függetlenül 1/100 egységben tárol; a SimplePay HUF-nál
egész forintot vár. Ábrázolhatatlan összegre hangos hiba, sosem kerekítés —
a régi implementáció minden pénznemre 100-zal osztott, ami HUF-nál
százszoros eltérés lett volna."
```

---

### Task 3: `Order\OrderReference` — az `orderRef` séma

**Files:**
- Create: `src/Order/OrderReference.php`
- Test: `tests/Unit/Order/OrderReferenceTest.php`

**Interfaces:**
- Consumes: `IncompletePaymentException` (Task 2)
- Produces:
  - `new OrderReference(string $orderNumber, int $paymentId, int $attempt)`
  - `OrderReference::build(string $orderNumber, int $paymentId, int $attempt): string`
  - `OrderReference::parse(string $reference): self` — hibás alak → `\InvalidArgumentException`
  - `OrderReference::tryParse(string $reference): ?self`
  - property-k: `$orderNumber`, `$paymentId`, `$attempt`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Order/OrderReferenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Order;

use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderReferenceTest extends TestCase
{
    public function testItBuildsTheReferenceWithTheOrderNumberFirst(): void
    {
        // A rendelésszám elöl marad, hogy a SimplePay kereskedői panelben
        // felismerhető legyen — ott ember nézi.
        self::assertSame('000000042-17-1', OrderReference::build('000000042', 17, 1));
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function references(): iterable
    {
        yield 'egyszerű rendelésszám' => ['000000042-17-1', '000000042', 17, 1];
        yield 'kötőjeles rendelésszám' => ['EZ-2026-0042-17-2', 'EZ-2026-0042', 17, 2];
        yield 'több kötőjel' => ['A-B-C-D-5-9', 'A-B-C-D', 5, 9];
        yield 'nagy azonosítók' => ['R1-123456-99', 'R1', 123456, 99];
    }

    #[DataProvider('references')]
    public function testItParsesFromTheRightSoDashesInTheOrderNumberAreSafe(
        string $reference,
        string $orderNumber,
        int $paymentId,
        int $attempt,
    ): void {
        $parsed = OrderReference::parse($reference);

        self::assertSame($orderNumber, $parsed->orderNumber);
        self::assertSame($paymentId, $parsed->paymentId);
        self::assertSame($attempt, $parsed->attempt);
    }

    #[DataProvider('references')]
    public function testBuildAndParseAreInverses(
        string $reference,
        string $orderNumber,
        int $paymentId,
        int $attempt,
    ): void {
        self::assertSame($reference, OrderReference::build($orderNumber, $paymentId, $attempt));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'nincs elég szegmens' => ['000000042-17'];
        yield 'nem szám a végén' => ['000000042-17-első'];
        yield 'nem szám középen' => ['000000042-tizenhét-1'];
        yield 'üres rendelésszám' => ['-17-1'];
        yield 'üres string' => [''];
        yield 'csak számok, de kevés' => ['1-2'];
        yield 'negatív azonosító' => ['R-{-1}-1'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedReferenceIsRejectedRatherThanPartiallyParsed(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderReference::parse($reference);
    }

    #[DataProvider('malformed')]
    public function testTryParseReturnsNullForTheSameMalformedInputs(string $reference): void
    {
        self::assertNull(OrderReference::tryParse($reference));
    }

    public function testALeadingZeroInTheCounterIsRejectedBecauseItIsNotWhatWeEmit(): void
    {
        // Nem szigorúskodás: ha elfogadnánk, a build/parse nem volna
        // kölcsönösen egyértelmű, és két különböző string ugyanarra a
        // fizetésre mutatna.
        $this->expectException(\InvalidArgumentException::class);

        OrderReference::parse('R-017-1');
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Order/OrderReferenceTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SyliusSimplePayPlugin\Order\OrderReference" not found`.

- [ ] **Step 3: Az `OrderReference` megírása**

`src/Order/OrderReference.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Order;

/**
 * A SimplePay `orderRef` séma: `{rendelésszám}-{paymentId}-{próbálkozás}`.
 *
 * MIÉRT NEM CSAK A RENDELÉSSZÁM (mint a régi implementációban): a Sylius
 * sikertelen fizetés után ÚJ `Payment` entitást hoz létre ugyanahhoz a
 * rendeléshez, és egy lejárt tranzakció újraindítása is új hivatkozást
 * igényel. Közös `orderRef` mellett a `QueryResponse::byOrderRef()` az
 * ELSŐ találatot adja vissza — néma keveredés két tranzakció között.
 *
 * A rendelésszám elöl marad, mert a SimplePay kereskedői panelben ember
 * nézi, és ott a rendelésszám az, amit keres. Ezért a visszafejtés JOBBRÓL
 * történik: az utolsó két kötőjeles szegmens a két szám, minden más előtte
 * a rendelésszám — így a kötőjelet tartalmazó rendelésszám is biztonságos.
 */
final readonly class OrderReference
{
    private const string PATTERN = '/^(?<orderNumber>.+)-(?<paymentId>0|[1-9]\d*)-(?<attempt>0|[1-9]\d*)$/';

    public function __construct(
        public string $orderNumber,
        public int $paymentId,
        public int $attempt,
    ) {
    }

    public static function build(string $orderNumber, int $paymentId, int $attempt): string
    {
        return sprintf('%s-%d-%d', $orderNumber, $paymentId, $attempt);
    }

    public static function parse(string $reference): self
    {
        return self::tryParse($reference) ?? throw new \InvalidArgumentException(sprintf(
            'A(z) "%s" nem érvényes SimplePay hivatkozás. A várt alak: '
            . '{rendelésszám}-{paymentId}-{próbálkozás}.',
            $reference,
        ));
    }

    public static function tryParse(string $reference): ?self
    {
        if (1 !== preg_match(self::PATTERN, $reference, $matches)) {
            return null;
        }

        return new self(
            orderNumber: $matches['orderNumber'],
            paymentId: (int) $matches['paymentId'],
            attempt: (int) $matches['attempt'],
        );
    }

    public function toString(): string
    {
        return self::build($this->orderNumber, $this->paymentId, $this->attempt);
    }
}
```

> **A `0|[1-9]\d*` minta szándékos:** kizárja a vezető nullát. Enélkül az
> `R-017-1` és az `R-17-1` ugyanarra a fizetésre mutatna, két különböző
> stringgel — a `build`/`parse` pár nem volna kölcsönösen egyértelmű.
> A `.+` a rendelésszámon mohó, ezért jobbról talál — pontosan ezt akarjuk.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Order/OrderReferenceTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Order tests/Unit/Order
git commit -m "feat(order): orderRef séma a fizetési kísérletek megkülönböztetésére

{rendelésszám}-{paymentId}-{próbálkozás}, jobbról parse-olva, hogy a
kötőjeles rendelésszám is biztonságos legyen. A régi implementáció csak a
rendelésszámot használta, amitől két tranzakció összekeveredett volna egy
sikertelen fizetés utáni újrapróbálkozáskor."
```

---

### Task 4: `Language\LocaleToLanguageMap`

**Files:**
- Create: `src/Language/LocaleToLanguageMap.php`
- Test: `tests/Unit/Language/LocaleToLanguageMapTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\Language`, `IncompletePaymentException` (Task 2)
- Produces: `LocaleToLanguageMap::resolve(?string $localeCode): Language`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Language/LocaleToLanguageMapTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Language;

use CodeConjure\SimplePay\Language;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Language\LocaleToLanguageMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleToLanguageMapTest extends TestCase
{
    /** @return iterable<string, array{string, Language}> */
    public static function locales(): iterable
    {
        yield 'hu_HU' => ['hu_HU', Language::Hu];
        yield 'csak hu' => ['hu', Language::Hu];
        yield 'en_US' => ['en_US', Language::En];
        yield 'en_GB' => ['en_GB', Language::En];
        yield 'de_DE' => ['de_DE', Language::De];
        yield 'de_AT' => ['de_AT', Language::De];
        yield 'kötőjeles alak' => ['en-GB', Language::En];
        yield 'nagybetűs alak' => ['HU_hu', Language::Hu];
    }

    #[DataProvider('locales')]
    public function testItResolvesTheKnownLocales(string $localeCode, Language $expected): void
    {
        self::assertSame($expected, LocaleToLanguageMap::resolve($localeCode));
    }

    public function testAnUnmappedLocaleIsLoudRatherThanSilentlyHungarian(): void
    {
        // A bolt ma egyetlen locale-t használ (hu_HU), tehát ez ma nem sülhet
        // el. Egy jövőbeli locale hozzáadásakor viszont AZONNAL látszik, hogy
        // a fizetőoldal nyelvéről dönteni kell — ahelyett, hogy egy francia
        // vevő csendben magyar fizetőoldalt kapna.
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('fr_FR');

        LocaleToLanguageMap::resolve('fr_FR');
    }

    public function testTheErrorNamesTheSupportedLanguages(): void
    {
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('HU');

        LocaleToLanguageMap::resolve('fr_FR');
    }

    public function testAMissingLocaleIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        LocaleToLanguageMap::resolve(null);
    }

    public function testAnEmptyLocaleIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        LocaleToLanguageMap::resolve('');
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Language/LocaleToLanguageMapTest.php
```

Elvárt: FAIL, `Class "…\Language\LocaleToLanguageMap" not found`.

- [ ] **Step 3: A `LocaleToLanguageMap` megírása**

`src/Language/LocaleToLanguageMap.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Language;

use CodeConjure\SimplePay\Language;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;

/**
 * Sylius locale → a SimplePay fizetőoldal nyelve.
 *
 * A leképezés explicit, és az ismeretlen locale HANGOS HIBA, nem csendes
 * magyar alapértelmezés. A bolt ma egyetlen locale-t használ (`hu_HU`),
 * tehát ez ma nem sülhet el — egy jövőbeli locale hozzáadásakor viszont
 * azonnal látszik, hogy a fizetőoldal nyelvéről dönteni kell.
 *
 * A protokoll-csomag `Language` enumja három nyelvet ismer; a SimplePay
 * többet is támogat, de azokat az 1. fázis nem modellezte.
 */
final class LocaleToLanguageMap
{
    /** @var array<string, Language> */
    private const array MAP = [
        'hu' => Language::Hu,
        'en' => Language::En,
        'de' => Language::De,
    ];

    public function resolve(?string $localeCode): Language
    {
        return self::resolveStatic($localeCode);
    }

    public static function resolveStatic(?string $localeCode): Language
    {
        if (null === $localeCode || '' === trim($localeCode)) {
            throw new IncompletePaymentException(
                'A rendeléshez nincs locale, ezért a SimplePay fizetőoldal nyelve nem határozható meg.',
            );
        }

        $primary = strtolower(preg_split('/[_-]/', trim($localeCode))[0] ?? '');

        return self::MAP[$primary] ?? throw new IncompletePaymentException(sprintf(
            'A(z) "%s" locale-hoz nincs SimplePay fizetőoldal-nyelv rendelve. A támogatottak: %s. '
            . 'Ha új nyelvet vezetsz be a boltban, itt kell dönteni a fizetőoldal nyelvéről.',
            $localeCode,
            implode(', ', array_map(
                static fn (Language $language): string => $language->value,
                array_values(self::MAP),
            )),
        ));
    }
}
```

> **Egy statikus és egy példány-metódus is van.** A statikus a tesztnek és a
> gyors használatnak kényelmes, a példány-metódus pedig azért kell, hogy a
> `ConvertPaymentAction` konstruktoron át kapja meg, és a viselkedés
> lecserélhető legyen anélkül, hogy az actiont kellene átírni. A teszt a
> statikus alakot hívja `LocaleToLanguageMap::resolve(...)` néven —
> **javítsd a tesztet `resolveStatic`-ra**, vagy tedd a `resolve()`-t
> statikussá és hagyd el a példány-metódust. **Döntsd el az implementációkor,
> és tartsd következetesen.** Az egyszerűbb út: csak `public static function
> resolve()`, példány-metódus nélkül, és a `ConvertPaymentAction` közvetlenül
> hívja — YAGNI, amíg nincs második leképezés.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Language/LocaleToLanguageMapTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Language tests/Unit/Language
git commit -m "feat(language): locale → fizetőoldal-nyelv, ismeretlenre hangos hiba

A bolt ma egyetlen locale-t használ, tehát ez ma nem sülhet el. Egy jövőbeli
nyelv hozzáadásakor viszont azonnal látszik, hogy dönteni kell — ahelyett,
hogy egy német vevő csendben magyar fizetőoldalt kapna."
```

---

### Task 5: `Gateway\GatewayConfigReader`

**Files:**
- Create: `src/Gateway/GatewayConfigReader.php`, `src/Gateway/SimplePayGatewaySettings.php`
- Test: `tests/Unit/Gateway/GatewayConfigReaderTest.php`

**Interfaces:**
- Consumes: `GatewayMismatchException` (Task 2)
- Produces:
  - `GatewayConfigReader::FACTORY_NAME = 'simplepay'`
  - `GatewayConfigReader::read(PaymentMethodInterface $paymentMethod): SimplePayGatewaySettings` — nem `simplepay` → `GatewayMismatchException`
  - `GatewayConfigReader::isSimplePay(PaymentMethodInterface $paymentMethod): bool`
  - `new SimplePayGatewaySettings(string $merchant, Environment $environment, Currency $currency)`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Gateway/GatewayConfigReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class GatewayConfigReaderTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function paymentMethod(?string $factoryName, array $config = []): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn(null === $factoryName ? null : $gatewayConfig);
        $paymentMethod->method('getCode')->willReturn('simplepay');

        return $paymentMethod;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ];
    }

    public function testItReadsTheSettingsFromASimplePayPaymentMethod(): void
    {
        $settings = GatewayConfigReader::read($this->paymentMethod('simplepay', self::config()));

        self::assertSame('PUBLICTESTHUF', $settings->merchant);
        self::assertSame(Environment::Sandbox, $settings->environment);
        self::assertSame(Currency::HUF, $settings->currency);
    }

    public function testItRefusesAPaymentMethodOfAnotherGateway(): void
    {
        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('offline');

        GatewayConfigReader::read($this->paymentMethod('offline', self::config()));
    }

    public function testItRefusesAPaymentMethodWithoutAGatewayConfig(): void
    {
        $this->expectException(GatewayMismatchException::class);

        GatewayConfigReader::read($this->paymentMethod(null));
    }

    public function testIsSimplePayAnswersWithoutThrowing(): void
    {
        self::assertTrue(GatewayConfigReader::isSimplePay($this->paymentMethod('simplepay', self::config())));
        self::assertFalse(GatewayConfigReader::isSimplePay($this->paymentMethod('offline')));
        self::assertFalse(GatewayConfigReader::isSimplePay($this->paymentMethod(null)));
    }

    public function testAnUnknownCurrencyInTheConfigIsNamedInTheError(): void
    {
        $config = self::config();
        $config['currency'] = 'GBP';

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('GBP');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAnUnknownEnvironmentInTheConfigIsNamedInTheError(): void
    {
        $config = self::config();
        $config['environment'] = 'staging';

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('staging');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }

    public function testAMissingCurrencyIsLoudNotDefaultedToForint(): void
    {
        $config = self::config();
        unset($config['currency']);

        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('currency');

        GatewayConfigReader::read($this->paymentMethod('simplepay', $config));
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Gateway/GatewayConfigReaderTest.php
```

Elvárt: FAIL, `Class "…\Gateway\GatewayConfigReader" not found`.

- [ ] **Step 3: A két osztály megírása**

`src/Gateway/SimplePayGatewaySettings.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;

/**
 * A gateway konfigurációjából kiolvasott, tipizált beállítások.
 *
 * A `secretKey` SZÁNDÉKOSAN nincs itt: ezt az objektumot naplózásra és
 * ellenőrzésre használjuk, a titok pedig a Payum gateway-en belül marad.
 * Egy titkot hordozó DTO előbb-utóbb megjelenik egy `var_dump`-ban vagy
 * egy hibajelentésben.
 */
final readonly class SimplePayGatewaySettings
{
    public function __construct(
        public string $merchant,
        public Environment $environment,
        public Currency $currency,
    ) {
    }
}
```

`src/Gateway/GatewayConfigReader.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Gateway;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * A Sylius gateway konfigurációjának tipizált olvasása.
 *
 * A konfiguráció egy `array<string, mixed>` az adatbázisban, tehát minden
 * érték gyanús, amíg nem ellenőriztük. Hiányzó vagy ismeretlen értékre
 * hangos hiba jár, ami MEGNEVEZI a kapott értéket — egy „hibás konfiguráció"
 * üzenetből az admin nem tudja, mit írjon át.
 */
final class GatewayConfigReader
{
    public const string FACTORY_NAME = 'simplepay';

    public static function read(PaymentMethodInterface $paymentMethod): SimplePayGatewaySettings
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $factoryName = $gatewayConfig?->getFactoryName();

        if (self::FACTORY_NAME !== $factoryName) {
            throw new GatewayMismatchException(sprintf(
                'A(z) "%s" fizetési mód nem SimplePay: a gateway factory neve "%s".',
                (string) $paymentMethod->getCode(),
                $factoryName ?? '(nincs gateway konfiguráció)',
            ));
        }

        $config = $gatewayConfig->getConfig();

        return new SimplePayGatewaySettings(
            merchant: self::string($config, 'merchant'),
            environment: self::enum(Environment::class, self::string($config, 'environment'), 'environment'),
            currency: self::enum(Currency::class, self::string($config, 'currency'), 'currency'),
        );
    }

    public static function isSimplePay(PaymentMethodInterface $paymentMethod): bool
    {
        return self::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName();
    }

    /** @param array<array-key, mixed> $config */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            throw new GatewayMismatchException(sprintf(
                'A SimplePay gateway konfigurációjából hiányzik a "%s" beállítás.',
                $key,
            ));
        }

        return trim($value);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, string $value, string $key): \BackedEnum
    {
        return $enum::tryFrom($value) ?? throw new GatewayMismatchException(sprintf(
            'A SimplePay gateway "%s" beállításának "%s" értéke ismeretlen. Az ismertek: %s.',
            $key,
            $value,
            implode(', ', array_column($enum::cases(), 'value')),
        ));
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Gateway/GatewayConfigReaderTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Gateway tests/Unit/Gateway
git commit -m "feat(gateway): a Sylius gateway config tipizált olvasása

A hibaüzenetek megnevezik a kapott értéket, hogy az admin tudja, mit írjon
át. A secretKey szándékosan nincs a beállítás-objektumban: az a Payum
gateway-en belül marad."
```

---

### Task 6: `Action\ConvertPaymentAction`

**Files:**
- Create: `src/Action/ConvertPaymentAction.php`
- Test: `tests/Unit/Action/ConvertPaymentActionTest.php`

**Interfaces:**
- Consumes: `SyliusAmountConverter` (Task 2), `OrderReference` (Task 3), `LocaleToLanguageMap` (Task 4), `GatewayConfigReader` (Task 5), `CodeConjure\SimplePayPayum\Details`, `CodeConjure\SimplePayPayum\Model\StartData`
- Produces: `new ConvertPaymentAction(UrlGeneratorInterface $urlGenerator)` — `ActionInterface`
- Route név, amire a négy visszatérési URL mutat: `codeconjure_simplepay_return` (a Task 9 hozza létre)

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Action/ConvertPaymentActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use Payum\Core\Request\Convert;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ConvertPaymentActionTest extends TestCase
{
    private const string TOKEN_HASH = 'token-hash-123';

    private function urlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            /** @param array<string, mixed> $parameters */
            static fn (string $route, array $parameters = []): string => sprintf(
                'https://bolt.hu/payment/simplepay/return?payum_token=%s&e=%s',
                (string) ($parameters['payum_token'] ?? ''),
                (string) ($parameters['e'] ?? ''),
            ),
        );

        return $generator;
    }

    private function payment(
        ?int $amount = 100000,
        ?string $currency = 'HUF',
        ?string $email = 'vevo@example.com',
        ?string $locale = 'hu_HU',
        bool $withAddress = true,
        string $factoryName = 'simplepay',
        string $gatewayCurrency = 'HUF',
    ): PaymentInterface {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getFullName')->willReturn('Teszt Elek');
        $address->method('getCountryCode')->willReturn('HU');
        $address->method('getCity')->willReturn('Budapest');
        $address->method('getPostcode')->willReturn('1011');
        $address->method('getStreet')->willReturn('Fő utca 1.');

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('EZ-2026-0042');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getLocaleCode')->willReturn($locale);
        $order->method('getBillingAddress')->willReturn($withAddress ? $address : null);

        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => $gatewayCurrency,
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn($currency);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn([]);

        return $payment;
    }

    private function token(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getHash')->willReturn(self::TOKEN_HASH);

        return $token;
    }

    /** @return array<string, mixed> */
    private function convert(PaymentInterface $payment): array
    {
        $request = new Convert($payment, 'array', $this->token());

        new ConvertPaymentAction($this->urlGenerator())->execute($request);

        /** @var array<string, mixed> $result */
        $result = $request->getResult();

        return $result;
    }

    public function testItBuildsTheRequestNamespaceThePayumPackageExpects(): void
    {
        $result = $this->convert($this->payment());

        self::assertArrayHasKey(Details::REQUEST_KEY, $result);

        /** @var array<string, mixed> $request */
        $request = $result[Details::REQUEST_KEY];

        // A StartData::fromArray() a Payum-csomag validációja: ha ez lefut,
        // a leképezés minden kötelező mezőt helyes típussal töltött ki.
        $startData = StartData::fromArray($request);

        self::assertSame('EZ-2026-0042-17-1', $startData->orderRef);
        self::assertSame(1000, $startData->total);
        self::assertSame('vevo@example.com', $startData->customerEmail);
        self::assertSame('Teszt Elek', $startData->invoice->name);
        self::assertSame('HU', $startData->invoice->country);
        self::assertSame('Budapest', $startData->invoice->city);
        self::assertSame('1011', $startData->invoice->zip);
        self::assertSame('Fő utca 1.', $startData->invoice->address);
        self::assertSame('HU', $startData->language->value);
        self::assertSame(['CARD'], array_map(
            static fn (\BackedEnum $method): string => (string) $method->value,
            $startData->methods,
        ));
    }

    public function testTheForintAmountIsConvertedToWholeForints(): void
    {
        // 100000 Sylius-egység = 1000,00 Ft → a SimplePay 1000-et vár.
        // A régi implementáció 100-zal osztott két tizedessel, ami
        // 1000.00-t küldött volna forint helyett.
        self::assertSame(1000, StartData::fromArray(
            $this->convert($this->payment())[Details::REQUEST_KEY],
        )->total);
    }

    public function testAllFourReturnUrlsArePresentAndCarryTheEvent(): void
    {
        $startData = StartData::fromArray($this->convert($this->payment())[Details::REQUEST_KEY]);

        self::assertStringContainsString('e=success', $startData->urls->success);
        self::assertStringContainsString('e=fail', $startData->urls->fail);
        self::assertStringContainsString('e=cancel', $startData->urls->cancel);
        self::assertStringContainsString('e=timeout', $startData->urls->timeout);
        self::assertStringContainsString(self::TOKEN_HASH, $startData->urls->success);
    }

    public function testTheAttemptStartsAtOneForAFreshPayment(): void
    {
        self::assertSame(1, StartData::fromArray(
            $this->convert($this->payment())[Details::REQUEST_KEY],
        )->attempt);
    }

    public function testTheAttemptIsOneMoreThanTheLastStartedTransaction(): void
    {
        $payment = $this->payment();
        $payment->method('getDetails')->willReturn([
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'TIMEOUT', 'attempt' => 2],
        ]);

        $result = $this->convert($payment);

        self::assertSame(3, StartData::fromArray($result[Details::REQUEST_KEY])->attempt);
        self::assertSame('EZ-2026-0042-17-3', StartData::fromArray($result[Details::REQUEST_KEY])->orderRef);
    }

    public function testTheExistingStateIsCarriedOverSoTheIpnLogSurvives(): void
    {
        $payment = $this->payment();
        $payment->method('getDetails')->willReturn([
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'TIMEOUT'],
        ]);

        $result = $this->convert($payment);

        self::assertArrayHasKey(Details::STATE_KEY, $result);
        self::assertSame('T1', $result[Details::STATE_KEY]['transactionId']);
    }

    public function testAPaymentCurrencyThatDiffersFromTheMerchantCurrencyIsRefused(): void
    {
        // A SimplePay merchant azonosító pénznemhez kötött: egy merchant
        // egy pénznemet fogad. Enélkül a kérés kimenne, és a SimplePay egy
        // nehezen értelmezhető hibakóddal utasítaná el.
        $this->expectException(GatewayMismatchException::class);
        $this->expectExceptionMessage('EUR');

        $this->convert($this->payment(currency: 'EUR', gatewayCurrency: 'HUF'));
    }

    public function testAPaymentMethodOfAnotherGatewayIsRefused(): void
    {
        $this->expectException(GatewayMismatchException::class);

        $this->convert($this->payment(factoryName: 'offline'));
    }

    public function testAMissingCustomerEmailIsLoudNotAnEmptyString(): void
    {
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('e-mail');

        $this->convert($this->payment(email: null));
    }

    public function testAMissingBillingAddressIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);
        $this->expectExceptionMessage('számlázási cím');

        $this->convert($this->payment(withAddress: false));
    }

    public function testAMissingAmountIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        $this->convert($this->payment(amount: null));
    }

    public function testAnUnrepresentableForintAmountIsLoud(): void
    {
        $this->expectException(UnrepresentableAmountException::class);

        $this->convert($this->payment(amount: 100050));
    }

    public function testAnUnmappedLocaleIsLoud(): void
    {
        $this->expectException(IncompletePaymentException::class);

        $this->convert($this->payment(locale: 'fr_FR'));
    }

    public function testItSupportsOnlyConversionOfASyliusPaymentToAnArray(): void
    {
        $action = new ConvertPaymentAction($this->urlGenerator());

        self::assertTrue($action->supports(new Convert($this->payment(), 'array')));
        self::assertFalse($action->supports(new Convert($this->payment(), 'json')));
        self::assertFalse($action->supports(new Convert(new \stdClass(), 'array')));
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/ConvertPaymentActionTest.php
```

Elvárt: FAIL, `Class "…\Action\ConvertPaymentAction" not found`.

- [ ] **Step 3: A `ConvertPaymentAction` megírása**

`src/Action/ConvertPaymentAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Action;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\PaymentMethod;
use CodeConjure\SimplePay\Request\Invoice;
use CodeConjure\SimplePay\Request\Urls;
use CodeConjure\SimplePay\ReturnEvent;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Language\LocaleToLanguageMap;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sylius `Payment` → a Payum-csomag `simplepay_request` névtere.
 *
 * EZ AZ AZ OSZTÁLY, AMI SYLIUST ISMER. A Payum-csomag `CaptureAction`-je
 * egy kész, gateway-független tömböt fogyaszt; ami rendelést, vevőt,
 * számlázási címet vagy Sylius pénz-ábrázolást ismer, az mind itt van.
 *
 * A régi implementációban ez a határ elmosódott: a `TransactionPayloadFactory`
 * egyszerre ismerte a Payum `Capture`-t, a Sylius `Order`-t és a SimplePay
 * mezőneveit.
 */
final class ConvertPaymentAction implements ActionInterface
{
    public const string RETURN_ROUTE = 'codeconjure_simplepay_return';

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Convert $request */
        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        $order = $payment->getOrder();

        if (!$order instanceof OrderInterface) {
            throw new IncompletePaymentException('A fizetéshez nem tartozik rendelés.');
        }

        $method = $payment->getMethod();

        if (!$method instanceof PaymentMethodInterface) {
            throw new GatewayMismatchException('A fizetéshez nem tartozik fizetési mód.');
        }

        $settings = GatewayConfigReader::read($method);
        $currency = $this->currency($payment, $settings->currency);

        $details = $payment->getDetails();
        $state = TransactionState::fromArray($this->stateArray($details));

        $startData = new StartData(
            orderRef: OrderReference::build(
                $this->orderNumber($order),
                $this->paymentId($payment),
                $state->attempt + 1,
            ),
            total: SyliusAmountConverter::toMinorUnits($this->amount($payment), $currency),
            currency: $currency,
            customerEmail: $this->customerEmail($order),
            invoice: $this->invoice($order),
            urls: $this->urls($request),
            language: LocaleToLanguageMap::resolve($order->getLocaleCode()),
            // Fixen CARD: a WIRE élőben sosem lett kipróbálva, és az
            // átutalásos folyamat állapotkezelését egyik csomag sem modellezi.
            methods: [PaymentMethod::Card],
            attempt: $state->attempt + 1,
        );

        $result = $details;
        $result[Details::REQUEST_KEY] = $startData->toArray();

        // A meglévő állapot megmarad: az IPN-napló egy korábbi, lejárt
        // próbálkozásból is értékes, és az `attempt` számláló forrása.
        if ([] !== $this->stateArray($details)) {
            $result[Details::STATE_KEY] = $this->stateArray($details);
        }

        $request->setResult($result);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert
            && 'array' === $request->getTo()
            && $request->getSource() instanceof PaymentInterface;
    }

    private function currency(PaymentInterface $payment, Currency $merchantCurrency): Currency
    {
        $code = $payment->getCurrencyCode();

        if (null === $code || '' === $code) {
            throw new IncompletePaymentException('A fizetéshez nincs pénznem.');
        }

        if ($code !== $merchantCurrency->value) {
            throw new GatewayMismatchException(sprintf(
                'A fizetés pénzneme "%s", a SimplePay merchant viszont "%s"-t fogad. '
                . 'A SimplePay merchant azonosító pénznemhez kötött: több pénznemhez '
                . 'több merchant és több fizetési mód kell.',
                $code,
                $merchantCurrency->value,
            ));
        }

        return $merchantCurrency;
    }

    private function amount(PaymentInterface $payment): int
    {
        return $payment->getAmount() ?? throw new IncompletePaymentException(
            'A fizetéshez nincs összeg.',
        );
    }

    private function orderNumber(OrderInterface $order): string
    {
        $number = $order->getNumber();

        return null === $number || '' === $number
            ? throw new IncompletePaymentException('A rendelésnek nincs száma.')
            : $number;
    }

    private function paymentId(PaymentInterface $payment): int
    {
        $id = $payment->getId();

        return is_int($id)
            ? $id
            : throw new IncompletePaymentException(
                'A fizetésnek még nincs azonosítója — a SimplePay hivatkozás nem állítható elő. '
                . 'A fizetést a capture előtt perzisztálni kell.',
            );
    }

    private function customerEmail(OrderInterface $order): string
    {
        $email = $order->getCustomer()?->getEmail();

        return null === $email || '' === $email
            ? throw new IncompletePaymentException('A rendeléshez nem tartozik vevő e-mail cím.')
            : $email;
    }

    private function invoice(OrderInterface $order): Invoice
    {
        $address = $order->getBillingAddress();

        if (!$address instanceof AddressInterface) {
            throw new IncompletePaymentException('A rendeléshez nem tartozik számlázási cím.');
        }

        return new Invoice(
            name: $this->addressField($address->getFullName(), 'név'),
            // ISO kód, NEM szöveges országnév: az 1. fázis élő kontraktus-
            // tesztje "HU"-t küldött, és a SimplePay elfogadta.
            country: $this->addressField($address->getCountryCode(), 'ország'),
            city: $this->addressField($address->getCity(), 'város'),
            zip: $this->addressField($address->getPostcode(), 'irányítószám'),
            address: $this->addressField($address->getStreet(), 'utca'),
        );
    }

    private function addressField(?string $value, string $label): string
    {
        return null === $value || '' === trim($value)
            ? throw new IncompletePaymentException(sprintf(
                'A számlázási cím "%s" mezője üres, enélkül a SimplePay kérés nem állítható össze.',
                $label,
            ))
            : trim($value);
    }

    private function urls(Convert $request): Urls
    {
        $token = $request->getToken();
        $hash = null === $token ? null : $token->getHash();

        if (null === $hash || '' === $hash) {
            throw new IncompletePaymentException(
                'A capture-höz nincs Payum token, ezért a visszatérési címek nem állíthatók elő.',
            );
        }

        return new Urls(
            success: $this->returnUrl($hash, ReturnEvent::Success),
            fail: $this->returnUrl($hash, ReturnEvent::Fail),
            cancel: $this->returnUrl($hash, ReturnEvent::Cancel),
            timeout: $this->returnUrl($hash, ReturnEvent::Timeout),
        );
    }

    private function returnUrl(string $hash, ReturnEvent $event): string
    {
        return $this->urlGenerator->generate(
            self::RETURN_ROUTE,
            ['payum_token' => $hash, 'e' => strtolower($event->value)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
```

> **A `ReturnEvent` enum a protokoll-csomagból jön**, hogy a négy esemény
> neve egy helyen legyen definiálva. A `strtolower()` azért kell, mert az
> enum értéke `SUCCESS`, az URL-ben viszont kisbetűs `e=success` a szokás —
> és a `parseReturn()` úgyis a SimplePay által küldött `r` paraméterből
> olvassa vissza az eseményt, nem a mi query paraméterünkből.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/ConvertPaymentActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Action tests/Unit/Action
git commit -m "feat(action): Sylius Payment leképezése a SimplePay payloadra

Ez az egyetlen osztály, ami Syliust ismer — a Payum-csomag kész tömböt
fogyaszt. Hiányzó vevő, cím, összeg vagy locale hangos hiba, nem üres mező.
A pénznem-őr megfogja, ha a fizetés pénzneme nem a merchanté."
```

---

### Task 7: Admin gateway űrlap és sablon

**Files:**
- Create: `src/Form/Type/SimplePayGatewayConfigurationType.php`, `src/Resources/views/admin/gateway_configuration.html.twig`
- Test: `tests/Unit/Form/Type/SimplePayGatewayConfigurationTypeTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\Currency`, `Environment`
- Produces: `SimplePayGatewayConfigurationType` a `sylius.gateway_configuration_type` taggel (`type: simplepay`, `label: SimplePay`)

Az űrlap négy mezőt állít, plusz egy csak olvasható IPN-URL sort.

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Form/Type/SimplePayGatewayConfigurationTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Form\Type;

use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class SimplePayGatewayConfigurationTypeTest extends TypeTestCase
{
    /** @return list<\Symfony\Component\Form\FormExtensionInterface> */
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    private function form(): FormInterface
    {
        return $this->factory->create(SimplePayGatewayConfigurationType::class);
    }

    public function testItExposesExactlyTheFourSettableFields(): void
    {
        $names = array_keys(iterator_to_array($this->form()));

        sort($names);

        self::assertSame(['currency', 'environment', 'merchant', 'secretKey'], $names);
    }

    public function testItSubmitsIntoThePayumConfigNamespace(): void
    {
        $form = $this->form();

        $form->submit([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        self::assertTrue($form->isSynchronized());

        /** @var array<string, mixed> $data */
        $data = $form->getData();

        self::assertSame([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ], $data);
    }

    public function testTheEnvironmentOffersOnlySandboxAndProduction(): void
    {
        $choices = $this->form()->get('environment')->getConfig()->getOption('choices');

        self::assertSame(['sandbox', 'production'], array_values((array) $choices));
    }

    public function testTheCurrencyOffersOnlyTheThreeSupportedOnes(): void
    {
        $choices = $this->form()->get('currency')->getConfig()->getOption('choices');

        self::assertSame(['HUF', 'EUR', 'USD'], array_values((array) $choices));
    }

    public function testTheLegacyLocaleAndAllowedCurrenciesFieldsAreGone(): void
    {
        // A nyelv a rendelésből jön, a pénznem pedig merchant-hez kötött,
        // nem lista. A régi űrlap mindkettőt megkérdezte.
        $form = $this->form();

        self::assertFalse($form->has('locale'));
        self::assertFalse($form->has('allowed_currencies'));
        self::assertFalse($form->has('sandbox'));
    }
}
```

> **Ha a `TypeTestCase` a Sylius nélkül nem futtatható** (mert a
> `symfony/form` nincs a függőségek között külön), vedd fel dev-függőségnek:
> `composer require --dev symfony/form symfony/validator symfony/options-resolver`.
> A Sylius úgyis behúzza őket, de a plugin saját tesztjének explicit
> függőségre van szüksége.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Form/Type/SimplePayGatewayConfigurationTypeTest.php
```

Elvárt: FAIL, `Class "…\Form\Type\SimplePayGatewayConfigurationType" not found`.

- [ ] **Step 3: Az űrlap megírása**

`src/Form/Type/SimplePayGatewayConfigurationType.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Form\Type;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A SimplePay gateway admin konfigurációja.
 *
 * Négy mező. A régi űrlap `locale` és `allowed_currencies` mezői megszűntek:
 * a fizetőoldal nyelve a rendelés locale-jából származik, a pénznem pedig
 * NEM lista, hanem egyetlen érték — a SimplePay merchant azonosító
 * pénznemhez kötött, egy merchant egy pénznemet fogad.
 *
 * Az `environment` a régi bool `sandbox` helyett választás: egy
 * `sandbox: false` érték nem mondja meg, hogy „éles" vagy „nem tudjuk".
 */
#[AutoconfigureTag(
    name: 'sylius.gateway_configuration_type',
    attributes: ['type' => 'simplepay', 'label' => 'SimplePay'],
)]
final class SimplePayGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('merchant', TextType::class, [
                'label' => 'codeconjure_simplepay.form.merchant',
                'help' => 'codeconjure_simplepay.form.merchant_help',
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('secretKey', TextType::class, [
                'label' => 'codeconjure_simplepay.form.secret_key',
                'help' => 'codeconjure_simplepay.form.secret_key_help',
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('environment', ChoiceType::class, [
                'label' => 'codeconjure_simplepay.form.environment',
                'choices' => array_column(Environment::cases(), 'value'),
                'choice_label' => static fn (string $value): string => 'codeconjure_simplepay.environment.' . $value,
                'expanded' => true,
                'multiple' => false,
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'codeconjure_simplepay.form.currency',
                'help' => 'codeconjure_simplepay.form.currency_help',
                'choices' => array_column(Currency::cases(), 'value'),
                'choice_label' => static fn (string $value): string => $value,
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'validation_groups' => ['sylius'],
        ]);
    }
}
```

> **A `choices` tömb értékei a kulcsok is egyben** (`array_column` sima
> listát ad), tehát a `choice_label` callback kapja a string értéket. Ez
> tudatos: a Payum config kulcsai stringek, nem enum-példányok — a
> `GatewayConfigReader` fordítja vissza őket enummá, hangos hibával.
> Enum-alapú `ChoiceType` itt csak egy plusz szerializálási réteget hozna.

- [ ] **Step 4: A sablon megírása**

`src/Resources/views/admin/gateway_configuration.html.twig`:

```twig
{% set configuration = hookable_metadata.context.form.gatewayConfig.config %}
{% set payment_method_code = hookable_metadata.context.form.vars.value.code|default(null) %}

<div class="row">
    <div class="col-12 col-md-6">
        {{ form_row(configuration.merchant) }}
    </div>
    <div class="col-12 col-md-6">
        {{ form_row(configuration.secretKey) }}
    </div>
    <div class="col-12 col-md-6">
        {{ form_row(configuration.currency) }}
    </div>
    <div class="col-12 col-md-6">
        {{ form_row(configuration.environment) }}
    </div>

    <div class="col-12">
        <div class="alert alert-info" role="alert">
            <h6 class="alert-heading">{{ 'codeconjure_simplepay.ipn.heading'|trans }}</h6>

            {% if payment_method_code %}
                <p class="mb-2">{{ 'codeconjure_simplepay.ipn.instructions'|trans }}</p>
                <code class="user-select-all">{{ url('codeconjure_simplepay_ipn', {'code': payment_method_code}) }}</code>
            {% else %}
                <p class="mb-0">{{ 'codeconjure_simplepay.ipn.after_save'|trans }}</p>
            {% endif %}
        </div>
    </div>
</div>
```

> **A create űrlapon a kód még nem létezik**, ezért ott az `after_save`
> üzenet jelenik meg. Nem találunk ki egy URL-t, ami később másra mutatna —
> egy rossz IPN-cím a vezérlőpanelben azt jelenti, hogy egyetlen értesítés
> sem érkezik meg, és ezt csak akkor vennénk észre, amikor már fizettek.

- [ ] **Step 5: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/Form/Type/SimplePayGatewayConfigurationTypeTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Form src/Resources/views
git commit -m "feat(admin): gateway űrlap négy mezővel és IPN-URL súgóval

Az environment a régi bool helyett választás, a currency egyetlen érték a
régi lista helyett — a merchant azonosító pénznemhez kötött. A locale mező
megszűnt: a nyelv a rendelésből jön. A create űrlapon még nincs kód, ezért
ott nem találunk ki IPN-URL-t."
```

---

### Task 8: `Controller\IpnController` — a tokenmentes IPN-végpont

**Files:**
- Create: `src/Controller/IpnController.php`, `src/Resources/config/routing.yaml`
- Test: `tests/Unit/Controller/IpnControllerTest.php`

**Interfaces:**
- Consumes: `GatewayConfigReader` (Task 5), `OrderReference` (Task 3), `CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn`
- Produces:
  - route `codeconjure_simplepay_ipn`: `POST /payment/simplepay/ipn/{code}`
  - `new IpnController(PaymentMethodRepositoryInterface $paymentMethodRepository, PaymentRepositoryInterface $paymentRepository, Payum $payum, EntityManagerInterface $entityManager, ReplyToSymfonyResponseConverter $replyConverter, LoggerInterface $logger)`

- [ ] **Step 1: A route megírása**

`src/Resources/config/routing.yaml`:

```yaml
codeconjure_simplepay_ipn:
    path: /payment/simplepay/ipn/{code}
    methods: [POST]
    controller: CodeConjure\SyliusSimplePayPlugin\Controller\IpnController
    requirements:
        code: '[A-Za-z0-9_-]+'

codeconjure_simplepay_return:
    path: /payment/simplepay/return
    methods: [GET]
    controller: CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController
```

> **Mindkét útvonal a shop locale-prefixén KÍVÜL van.** A bolt útvonalai
> `/{_locale}` prefixszel töltődnek be; egy `/hu_HU/payment/simplepay/ipn/…`
> alakú IPN-cím a vezérlőpanelben törékeny és félrevezető volna. Az app a
> plugin routing fájlját külön importálja (lásd Task 15).

- [ ] **Step 2: A bukó teszt megírása**

`tests/Unit/Controller/IpnControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Controller;

use CodeConjure\SimplePay\Exception\SignatureException;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Bundle\PayumBundle\ReplyToSymfonyResponseConverter;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Registry\RegistryInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IpnControllerTest extends TestCase
{
    private const string BODY = '{"orderRef":"EZ-2026-0042-17-1","status":"FINISHED"}';
    private const string CONFIRMATION = '{"orderRef":"EZ-2026-0042-17-1","status":"FINISHED","receiveDate":"…"}';

    private function paymentMethod(string $factoryName = 'simplepay'): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        return $method;
    }

    /**
     * @param list<object> $executed a gateway által látott requestek
     */
    private function gateway(array &$executed, ?\Throwable $resolveThrows = null): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use (&$executed, $resolveThrows): void {
                $executed[] = $request;

                if ($request instanceof ResolveSimplePayIpn) {
                    if (null !== $resolveThrows) {
                        throw $resolveThrows;
                    }

                    $message = new \CodeConjure\SimplePay\Ipn\IpnMessage(
                        merchant: 'PUBLICTESTHUF',
                        orderRef: 'EZ-2026-0042-17-1',
                        transactionId: '99844942',
                        status: \CodeConjure\SimplePay\TransactionStatus::Finished,
                    );

                    $request->setMessage($message);

                    return;
                }

                if ($request instanceof Notify) {
                    throw new HttpResponse(self::CONFIRMATION, 200, ['Signature' => 'aláírás']);
                }
            },
        );

        return $gateway;
    }

    /** @param list<object> $executed */
    private function controller(
        array &$executed,
        ?PaymentMethodInterface $paymentMethod,
        ?PaymentInterface $payment,
        ?\Throwable $resolveThrows = null,
    ): IpnController {
        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->method('findOneBy')->willReturn($paymentMethod);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->method('find')->willReturn($payment);

        $registry = $this->createMock(RegistryInterface::class);
        $registry->method('getGateway')->willReturn($this->gateway($executed, $resolveThrows));

        $payum = $this->createMock(Payum::class);
        $payum->method('getGateway')->willReturn($this->gateway($executed, $resolveThrows));

        return new IpnController(
            $paymentMethodRepository,
            $paymentRepository,
            $payum,
            $this->createMock(EntityManagerInterface::class),
            new ReplyToSymfonyResponseConverter(),
            new NullLogger(),
        );
    }

    private function request(): Request
    {
        return Request::create(
            '/payment/simplepay/ipn/simplepay',
            'POST',
            server: ['HTTP_SIGNATURE' => 'aláírás'],
            content: self::BODY,
        );
    }

    public function testAnAuthenticatedNotificationIsAnsweredWithTheSignedConfirmation(): void
    {
        $executed = [];
        $payment = $this->createMock(PaymentInterface::class);

        $response = $this->controller($executed, $this->paymentMethod(), $payment)(
            $this->request(),
            'simplepay',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CONFIRMATION, $response->getContent());
        self::assertSame('aláírás', $response->headers->get('Signature'));
    }

    public function testItResolvesBeforeItLooksUpThePaymentSoItNeverTrustsAnUnverifiedBody(): void
    {
        $executed = [];
        $payment = $this->createMock(PaymentInterface::class);

        $this->controller($executed, $this->paymentMethod(), $payment)($this->request(), 'simplepay');

        self::assertInstanceOf(ResolveSimplePayIpn::class, $executed[0]);
        self::assertInstanceOf(Notify::class, $executed[1]);
    }

    public function testAnUnknownPaymentMethodCodeIsANotFound(): void
    {
        $executed = [];

        $this->expectException(NotFoundHttpException::class);

        $this->controller($executed, null, null)($this->request(), 'nincs-ilyen');
    }

    public function testAPaymentMethodOfAnotherGatewayIsANotFound(): void
    {
        $executed = [];

        $this->expectException(NotFoundHttpException::class);

        $this->controller($executed, $this->paymentMethod('offline'), null)($this->request(), 'simplepay');
    }

    public function testAForgedSignatureProducesA400AndNoConfirmation(): void
    {
        $executed = [];

        $response = $this->controller(
            $executed,
            $this->paymentMethod(),
            null,
            new SignatureException('hamis aláírás'),
        )($this->request(), 'simplepay');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->headers->get('Signature', ''));
    }

    public function testAnUnknownOrderIsStillConfirmedSoSimplePayStopsRetrying(): void
    {
        // Ha egy nem létező rendelésre nem válaszolunk, a SimplePay örökké
        // ismételne. Visszaigazolunk, és error szinten naplózunk.
        $executed = [];

        $response = $this->controller($executed, $this->paymentMethod(), null)($this->request(), 'simplepay');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', (string) $response->getContent());

        self::assertCount(1, $executed, 'Ismeretlen rendelésnél a Notify nem futhat le.');
    }

    public function testAMissingSignatureHeaderProducesA400(): void
    {
        $executed = [];

        $request = Request::create(
            '/payment/simplepay/ipn/simplepay',
            'POST',
            content: self::BODY,
        );

        $response = $this->controller($executed, $this->paymentMethod(), null)($request, 'simplepay');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $executed, 'Aláírás nélkül a gateway-t meg sem szabad szólítani.');
    }
}
```

> **A `testAnUnknownOrderIsStillConfirmedSoSimplePayStopsRetrying` teszt egy
> nyitott kérdést hagy az implementernek:** ha nincs `Payment`, honnan jön az
> aláírt visszaigazolás törzse? A `Notify` nem futhat, mert nincs modell.
> **A megoldás:** a controller ilyenkor `Notify(new \ArrayObject([]))`-t
> futtat — üres, eldobható modellel. A `NotifyAction` így is előállítja a
> visszaigazolást, csak nem lesz hová írnia az állapotot. Ha ezt választod,
> a teszt `assertCount(1, ...)` sorát írd át `assertCount(2, ...)`-re, és
> ellenőrizd, hogy a `Notify` modellje NEM a Sylius `Payment`.
> **Döntsd el az implementációkor, és a döntést írd le a kódba kommentként.**

- [ ] **Step 3: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Controller/IpnControllerTest.php
```

Elvárt: FAIL, `Class "…\Controller\IpnController" not found`.

- [ ] **Step 4: Az `IpnController` megírása**

`src/Controller/IpnController.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Controller;

use CodeConjure\SimplePay\Exception\SimplePayException;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Bundle\PayumBundle\ReplyToSymfonyResponseConverter;
use Payum\Core\Payum;
use Payum\Core\Reply\ReplyInterface;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A SimplePay értesítéseinek (IPN) fogadása.
 *
 * A SimplePay EGYETLEN, a kereskedői vezérlőpanelen beállított URL-re
 * posztol, token nélkül — per-kérésben nincs IPN-cím mező. A payment method
 * kódja ezért az ÚTVONALBAN van: ezzel eltűnik a „melyik merchanthez tartozik
 * ez az üzenet?" tojás-tyúk kérdés, és nem kell az aláíratlan törzsből
 * olvasnunk semmit ahhoz, hogy megtaláljuk a hitelesítéshez szükséges titkot.
 *
 * A feldolgozás sorrendje szándékos:
 *   1. payment method a kódból (útvonal, nem törzs)
 *   2. ResolveSimplePayIpn — ellenőriz és parse-ol, modell nélkül
 *   3. Payment keresés a MOST MÁR HITELESÍTETT orderRef alapján
 *   4. Notify — állapotgép és aláírt visszaigazolás
 */
final class IpnController
{
    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param PaymentRepositoryInterface<PaymentInterface>             $paymentRepository
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReplyToSymfonyResponseConverter $replyConverter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);

        if (!$paymentMethod instanceof PaymentMethodInterface || !GatewayConfigReader::isSimplePay($paymentMethod)) {
            throw new NotFoundHttpException(sprintf(
                'Nincs "%s" kódú SimplePay fizetési mód.',
                $code,
            ));
        }

        $signature = (string) $request->headers->get('Signature', '');

        if ('' === trim($signature)) {
            $this->logger->error('SimplePay értesítés érkezett Signature fejléc nélkül.', [
                'event' => 'simplepay.ipn.unsigned',
                'payment_method' => $code,
                'client_ip' => $request->getClientIp(),
            ]);

            return new Response('Missing Signature header.', Response::HTTP_BAD_REQUEST);
        }

        $gateway = $this->payum->getGateway($code);

        try {
            $gateway->execute($resolve = new ResolveSimplePayIpn($request->getContent(), $signature));
        } catch (SimplePayException $exception) {
            // Hamis aláírás vagy idegen merchant. SOSEM újrapróbálandó, és
            // visszaigazolás sem jár érte — ha válaszolnánk, egy támadó
            // aláírás nélkül is le tudná állítani a valódi értesítéseket.
            $this->logger->error('SimplePay értesítés hitelesítése sikertelen.', [
                'event' => 'simplepay.ipn.rejected',
                'payment_method' => $code,
                'client_ip' => $request->getClientIp(),
                'reason' => $exception->getMessage(),
            ]);

            return new Response('Invalid notification.', Response::HTTP_BAD_REQUEST);
        }

        $message = $resolve->getMessage();
        $payment = $this->findPayment($message->orderRef);

        if (!$payment instanceof PaymentInterface) {
            $this->logger->error('SimplePay értesítés érkezett ismeretlen rendelésre.', [
                'event' => 'simplepay.ipn.unknown_order',
                'order_ref' => $message->orderRef,
                'transaction_id' => $message->transactionId,
            ]);
        }

        // Ismeretlen rendelésnél is aláírt visszaigazolás megy: a SimplePay
        // különben örökké ismételne egy olyan üzenetet, amivel sosem fogunk
        // tudni mit kezdeni. A modell ilyenkor egy eldobható ArrayObject —
        // a NotifyAction előállítja a visszaigazolást, csak nincs hová
        // írnia az állapotot.
        $model = $payment ?? new \ArrayObject([]);

        try {
            $gateway->execute(new Notify($model));
        } catch (ReplyInterface $reply) {
            if ($payment instanceof PaymentInterface) {
                $this->entityManager->flush();
            }

            return $this->replyConverter->convert($reply);
        }

        // Ide nem szabadna eljutni: a NotifyAction mindig reply-t dob.
        $this->logger->error('A SimplePay NotifyAction nem adott visszaigazolást.', [
            'event' => 'simplepay.ipn.no_confirmation',
            'order_ref' => $message->orderRef,
        ]);

        return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function findPayment(string $orderRef): ?PaymentInterface
    {
        $reference = OrderReference::tryParse($orderRef);

        if (null === $reference) {
            return null;
        }

        $payment = $this->paymentRepository->find($reference->paymentId);

        if (!$payment instanceof PaymentInterface) {
            return null;
        }

        // Keresztellenőrzés: a hivatkozásban lévő rendelésszámnak egyeznie
        // kell a megtalált fizetés rendelésével. Enélkül egy elgépelt vagy
        // átfedő azonosító idegen rendelést módosíthatna.
        if ($payment->getOrder()?->getNumber() !== $reference->orderNumber) {
            $this->logger->error('A SimplePay hivatkozás rendelésszáma nem egyezik a megtalált fizetésével.', [
                'event' => 'simplepay.ipn.order_mismatch',
                'order_ref' => $orderRef,
                'payment_id' => $reference->paymentId,
            ]);

            return null;
        }

        return $payment;
    }
}
```

- [ ] **Step 5: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/Controller/IpnControllerTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Controller/IpnController.php src/Resources/config/routing.yaml tests/Unit/Controller
git commit -m "feat(ipn): tokenmentes végpont a payment method kódjával

A kód az útvonalban van, ezért a hitelesítéshez szükséges titok megvan,
mielőtt a törzshöz nyúlnánk. A feloldás megelőzi a fizetés keresését, tehát
sosem keresünk rekordot aláíratlan adat alapján. Ismeretlen rendelésre is
megy visszaigazolás, különben a SimplePay örökké ismételne."
```

---

### Task 9: `Controller\ReturnController` — visszatérés a fizetőoldalról

**Files:**
- Create: `src/Controller/ReturnController.php`
- Test: `tests/Unit/Controller/ReturnControllerTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\Client::parseReturn()` a gateway-en át (`Sync`, `GetHumanStatus`)
- Produces: route `codeconjure_simplepay_return` (a Task 8 routing fájlja már tartalmazza)

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Controller/ReturnControllerTest.php`:

```php
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
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class ReturnControllerTest extends TestCase
{
    private const string AFTER_URL = 'https://bolt.hu/rendeles/koszonjuk';

    /** @param list<object> $executed */
    private function controller(array &$executed, ?\Throwable $syncThrows = null): ReturnController
    {
        $payment = $this->createMock(PaymentInterface::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn(self::AFTER_URL);
        $token->method('getGatewayName')->willReturn('simplepay');

        $verifier = $this->createMock(HttpRequestVerifierInterface::class);
        $verifier->method('verify')->willReturn($token);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use (&$executed, $syncThrows, $payment): void {
                $executed[] = $request;

                if ($request instanceof Sync && null !== $syncThrows) {
                    throw $syncThrows;
                }

                if ($request instanceof GetHumanStatus) {
                    $request->markCaptured();
                }
            },
        );

        $payum = $this->createMock(Payum::class);
        $payum->method('getHttpRequestVerifier')->willReturn($verifier);
        $payum->method('getGateway')->willReturn($gateway);

        return new ReturnController(
            $payum,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private function request(string $query = 'payum_token=abc&e=success&r=cmF3&s=c2ln'): Request
    {
        return Request::create('/payment/simplepay/return?' . $query);
    }

    public function testItSyncsBeforeItReadsTheStatus(): void
    {
        $executed = [];

        $this->controller($executed)($this->request());

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
        // Az r/s hiánya nem hiba: tájékoztató adat, ami sosem dönt.
        $executed = [];

        $response = $this->controller($executed)($this->request('payum_token=abc'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertNotSame([], $executed, 'A Sync akkor is le kell fusson, ha nincs r/s.');
    }

    public function testItNeverDecidesTheStateFromTheReturnPayload(): void
    {
        // Az r paraméter azt mondja, mit LÁT a vásárló — nem azt, hogy a
        // pénz megérkezett-e. Az állapotot a Sync dönti el.
        $executed = [];

        $this->controller($executed)($this->request('payum_token=abc&e=fail&r=cmF3&s=c2ln'));

        self::assertInstanceOf(Sync::class, $executed[0]);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Controller/ReturnControllerTest.php
```

Elvárt: FAIL, `Class "…\Controller\ReturnController" not found`.

- [ ] **Step 3: A `ReturnController` megírása**

`src/Controller/ReturnController.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Psr\Log\LoggerInterface;
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
    public function __construct(
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
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
```

> **Egy egyszerűsítés a spechez képest, és az indoka.** A spec 7. fejezete
> `parseReturn()`-t írt elő az `r`/`s` keresztellenőrzésével. Az
> implementáció ehelyett csak naplózza a paraméterek jelenlétét. Ok: a
> `parseReturn()` a protokoll-kliensen van, amihez a controllernek nincs
> közvetlen hozzáférése (a `Client` a Payum `Api`-ban él, a gateway mögött),
> és egy külön Payum request bevezetése csak a naplózásért nem éri meg —
> főleg mert az eredmény a spec szerint úgysem dönt semmit.
> **Ha a keresztellenőrzést mégis akarod**, az egy külön Payum request a
> Payum-csomagban (`ParseSimplePayReturn`), és egy külön task. A jelenlegi
> alak nem veszít információt: a `Sync` úgyis lekérdezi az igazi állapotot.

- [ ] **Step 4: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/Controller/ReturnControllerTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Controller/ReturnController.php tests/Unit/Controller/ReturnControllerTest.php
git commit -m "feat(return): visszatérés a Sylius standard folyamatára bízva

Az állapotot a Sync dönti el, sosem a böngészőn átjött r/s adat. Saját
visszatérési sablon nincs: a token afterUrl-jére irányítunk, vagyis a Sylius
köszönő/hibaoldalára. A sikertelen lekérdezés naplózódik, de nem töri el a
vevő oldalát — az IPN úgyis megérkezik."
```

---

### Task 10: `Debug\RecordingHttpClient` — a mérés műszere

**Files:**
- Create: `src/Debug/RecordingHttpClient.php`
- Test: `tests/Unit/Debug/RecordingHttpClientTest.php`

**Interfaces:**
- Consumes: `Psr\Http\Client\ClientInterface`
- Produces:
  - `new RecordingHttpClient(ClientInterface $inner, string $directory, bool $enabled = false)`
  - `RecordingHttpClient::sendRequest(RequestInterface $request): ResponseInterface`
  - `RecordingHttpClient::enable(): void`, `disable(): void`, `recordedFiles(): list<string>`

Ez az egyetlen eszköz zárja le a protokoll-csomag **mindkét** nyitott kérdését:
a sikeres jóváírás válaszalakját és a `detailed: true` extra mezőit.

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Debug/RecordingHttpClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Debug;

use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RecordingHttpClientTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/simplepay-recording-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            self::fail(sprintf('Nem hozható létre a teszt könyvtár: %s', $directory));
        }

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function inner(string $responseBody = '{"ok":true}'): MockHttpClient
    {
        $client = new MockHttpClient();
        $client->addResponse(new Response(200, ['Signature' => 'aláírás'], $responseBody));

        return $client;
    }

    private function request(string $body = '{"orderRef":"X"}'): \Psr\Http\Message\RequestInterface
    {
        $factory = new Psr17Factory();

        return $factory
            ->createRequest('POST', 'https://sandbox.simplepay.hu/payment/v2/refund')
            ->withBody($factory->createStream($body));
    }

    public function testDisabledItRecordsNothingAndStillReturnsTheResponse(): void
    {
        $client = new RecordingHttpClient($this->inner(), $this->directory);

        $response = $client->sendRequest($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $client->recordedFiles());
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testEnabledItWritesTheRawRequestAndResponseBodies(): void
    {
        $client = new RecordingHttpClient($this->inner('{"refundTotal":500}'), $this->directory, enabled: true);

        $client->sendRequest($this->request('{"refundTotal":"500"}'));

        $files = $client->recordedFiles();

        self::assertCount(2, $files);

        $contents = array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $files,
        );

        self::assertContains('{"refundTotal":"500"}', $contents);
        self::assertContains('{"refundTotal":500}', $contents);
    }

    public function testTheFileNamesCarryTheEndpointSoTheyAreIdentifiable(): void
    {
        $client = new RecordingHttpClient($this->inner(), $this->directory, enabled: true);

        $client->sendRequest($this->request());

        foreach ($client->recordedFiles() as $file) {
            self::assertStringContainsString('refund', basename($file));
        }
    }

    public function testTheRequestBodyIsStillReadableByTheInnerClient(): void
    {
        // A PSR-7 stream egyszer olvasható; ha a rögzítés elfogyasztja,
        // a valódi kérés üres törzzsel menne ki. Ez a teszt őrzi, hogy
        // visszatekerjük.
        $inner = $this->inner();
        $client = new RecordingHttpClient($inner, $this->directory, enabled: true);

        $client->sendRequest($this->request('{"orderRef":"X"}'));

        self::assertSame('{"orderRef":"X"}', (string) $inner->getLastRequest()?->getBody());
    }

    public function testTheResponseBodyIsStillReadableByTheCaller(): void
    {
        $client = new RecordingHttpClient($this->inner('{"ok":true}'), $this->directory, enabled: true);

        $response = $client->sendRequest($this->request());

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testItCanBeTurnedOnAtRuntime(): void
    {
        $inner = new MockHttpClient();
        $inner->addResponse(new Response(200, [], '{"a":1}'));
        $inner->addResponse(new Response(200, [], '{"b":2}'));

        $client = new RecordingHttpClient($inner, $this->directory);

        $client->sendRequest($this->request());
        $client->enable();
        $client->sendRequest($this->request());

        self::assertCount(2, $client->recordedFiles(), 'Csak a bekapcsolás utáni hívás rögzül.');
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Debug/RecordingHttpClientTest.php
```

Elvárt: FAIL, `Class "…\Debug\RecordingHttpClient" not found`.

- [ ] **Step 3: A `RecordingHttpClient` megírása**

`src/Debug/RecordingHttpClient.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Debug;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 dekorátor, ami a nyers kérés- és válasz-törzseket fájlba menti.
 *
 * EZ A MÉRÉS MŰSZERE. A protokoll-csomagnak két nyitott kérdése van, amit
 * csak valódi forgalomból lehet lezárni:
 *
 *   1. egy SIKERES jóváírás válaszának pontos alakja (ma dokumentációból
 *      származó mezőkészlet, nem mérés),
 *   2. a `detailed: true` lekérdezés extra mezői.
 *
 * A rögzített nyers JSON a protokoll-csomagba kerül fixture-ként — az
 * érzékeny mezők (`customer`, `customerEmail`, `invoice`, `salt`) értéke
 * `"[REDACTED]"`-re cserélve, de a KULCSOKAT megtartva, hogy a fixture
 * bizonyítani tudja, melyik mezőt küldi ténylegesen a SimplePay.
 *
 * ALAPÉRTELMEZÉSBEN KI VAN KAPCSOLVA. A `detailed: true` miatt minden
 * lekérdezés válasza tartalmazza a vevő nevét, e-mail címét és számlázási
 * címét — bekapcsolva ezek lemezre kerülnek. Éles környezetben csak
 * tudatosan, rövid ideig, és a fájlokat utána törölni kell.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<string> */
    private array $recordedFiles = [];

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $directory,
        private bool $enabled = false,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /** @return list<string> */
    public function recordedFiles(): array
    {
        return $this->recordedFiles;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->enabled) {
            return $this->inner->sendRequest($request);
        }

        $prefix = sprintf(
            '%s/%s-%s',
            rtrim($this->directory, '/'),
            new \DateTimeImmutable()->format('Ymd-His-u'),
            $this->endpoint($request),
        );

        $this->write($prefix . '.req.json', $this->readBody($request->getBody()));

        $response = $this->inner->sendRequest($request);

        $this->write($prefix . '.res.json', $this->readBody($response->getBody()));

        return $response;
    }

    private function endpoint(RequestInterface $request): string
    {
        $segment = basename($request->getUri()->getPath());

        return '' === $segment ? 'unknown' : preg_replace('/[^a-z0-9_-]/i', '', $segment) ?? 'unknown';
    }

    /**
     * A PSR-7 stream egyszer olvasható. Ha nem tekerjük vissza, a valódi
     * kérés üres törzzsel menne ki, a hívó pedig üres választ kapna —
     * a műszer így elrontaná azt, amit mérni akar.
     */
    private function readBody(\Psr\Http\Message\StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $contents = $stream->getContents();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Nem írható a felvételi fájl: %s', $path));
        }

        $this->recordedFiles[] = $path;
    }
}
```

- [ ] **Step 4: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/Debug/RecordingHttpClientTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Debug tests/Unit/Debug
git commit -m "feat(debug): nyers HTTP-forgalom rögzítése a méréshez

Ez az egyetlen eszköz zárja le a protokoll-csomag mindkét nyitott kérdését:
a sikeres jóváírás válaszalakját és a detailed lekérdezés extra mezőit.
Alapértelmezésben kikapcsolva — bekapcsolva a vevő neve, e-mailje és
számlázási címe is lemezre kerül."
```

---

### Task 11: `Command\RefundCommand`

**Files:**
- Create: `src/Command/RefundCommand.php`
- Test: `tests/Unit/Command/RefundCommandTest.php`

**Interfaces:**
- Consumes: `SyliusAmountConverter` (Task 2), `GatewayConfigReader` (Task 5), `RecordingHttpClient` (Task 10), `CodeConjure\SimplePayPayum\Details`
- Produces: `simplepay:refund <orderNumber> [--amount=] [--record]`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Command/RefundCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Command;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Doctrine\Common\Collections\ArrayCollection;

final class RefundCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $details = [];

    private function payment(string $state = 'completed', ?int $amount = 100000): PaymentInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn('simplepay');
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getState')->willReturn($state);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn('HUF');
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturnCallback(fn (): array => $this->details);
        $payment->method('setDetails')->willReturnCallback(
            /** @param array<string, mixed> $details */
            function (array $details): void {
                $this->details = $details;
            },
        );

        return $payment;
    }

    /** @param list<object> $executed */
    private function tester(array &$executed, ?PaymentInterface $payment, ?string $orderNumber = 'EZ-2026-0042'): CommandTester
    {
        $order = null;

        if (null !== $orderNumber) {
            $order = $this->createMock(OrderInterface::class);
            $order->method('getNumber')->willReturn($orderNumber);
            $order->method('getPayments')->willReturn(
                new ArrayCollection(null === $payment ? [] : [$payment]),
            );
        }

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('findOneBy')->willReturn($order);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            function (object $request) use (&$executed): void {
                $executed[] = $request;

                if ($request instanceof Refund) {
                    $this->details[Details::STATE_KEY] = array_merge(
                        $this->details[Details::STATE_KEY] ?? [],
                        ['refundTransactionId' => '509007601', 'refundTotal' => 500, 'remainingTotal' => 500],
                    );
                }
            },
        );

        $payum = $this->createMock(Payum::class);
        $payum->method('getGateway')->willReturn($gateway);

        $command = new RefundCommand(
            $orderRepository,
            $payum,
            $this->createMock(EntityManagerInterface::class),
            new RecordingHttpClient(
                $this->createMock(\Psr\Http\Client\ClientInterface::class),
                sys_get_temp_dir(),
            ),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('simplepay:refund'));
    }

    public function testItRefundsTheFullAmountWhenNoneIsGiven(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester($executed, $this->payment());
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(0, $exitCode);
        // 100000 Sylius-egység = 1000 Ft: a parancs számolja ki, nem az action.
        self::assertSame(1000, $this->details[Details::REFUND_KEY]['amount']);
    }

    public function testItRefundsTheGivenAmountConvertedToTheCurrencyMinorUnit(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester($executed, $this->payment());
        $tester->execute(['orderNumber' => 'EZ-2026-0042', '--amount' => '50000']);

        self::assertSame(500, $this->details[Details::REFUND_KEY]['amount']);
    }

    public function testItPrintsTheRefundResultSoTheOperatorSeesWhatHappened(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester($executed, $this->payment());
        $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        $output = $tester->getDisplay();

        self::assertStringContainsString('509007601', $output);
        self::assertStringContainsString('500', $output);
    }

    public function testAnUnknownOrderFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, null, orderNumber: null);
        $exitCode = $tester->execute(['orderNumber' => 'NINCS-ILYEN']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('NINCS-ILYEN', $tester->getDisplay());
        self::assertSame([], $executed);
    }

    public function testAnOrderWithoutASimplePayPaymentFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, null);
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertSame([], $executed);
    }

    public function testAPaymentThatIsNotCompletedFails(): void
    {
        $executed = [];

        $tester = $this->tester($executed, $this->payment(state: 'new'));
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('completed', $tester->getDisplay());
        self::assertSame([], $executed);
    }

    public function testAnUnrepresentableAmountFailsRatherThanRounding(): void
    {
        $executed = [];
        $this->details = [Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED']];

        $tester = $this->tester($executed, $this->payment());
        $exitCode = $tester->execute(['orderNumber' => 'EZ-2026-0042', '--amount' => '50050']);

        self::assertSame(1, $exitCode);
        self::assertSame([], $executed);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Command/RefundCommandTest.php
```

Elvárt: FAIL, `Class "…\Command\RefundCommand" not found`.

- [ ] **Step 3: A `RefundCommand` megírása**

`src/Command/RefundCommand.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Command;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\Exception\SyliusSimplePayException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jóváírás indítása parancssorból.
 *
 * Admin felület szándékosan nincs: ez a parancs elég ahhoz, hogy egy valódi
 * jóváírást elindítsunk és a `--record` kapcsolóval rögzítsük a nyers
 * választ — amivel a protokoll-csomag egyik nyitott kérdése lezárható.
 *
 * A TELJES ÖSSZEG KISZÁMÍTÁSA ITT TÖRTÉNIK, nem a `RefundAction`-ben. Az
 * action sosem alapértelmez „teljes összeg"-re; a döntés itt születik, ahol
 * látható, naplózható, és az operátor a képernyőn is látja.
 */
#[AsCommand(
    name: 'simplepay:refund',
    description: 'SimplePay jóváírás indítása egy rendelés befejezett fizetésére.',
)]
final class RefundCommand extends Command
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecordingHttpClient $recordingHttpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('orderNumber', InputArgument::REQUIRED, 'A rendelés száma.')
            ->addOption(
                'amount',
                null,
                InputOption::VALUE_REQUIRED,
                'A jóváírandó összeg Sylius-egységben (1/100). Megadás nélkül a teljes fizetett összeg.',
            )
            ->addOption(
                'record',
                null,
                InputOption::VALUE_NONE,
                'A nyers HTTP kérés és válasz mentése fájlba, a válaszalak méréséhez.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderNumber = (string) $input->getArgument('orderNumber');

        $order = $this->orderRepository->findOneBy(['number' => $orderNumber]);

        if (!$order instanceof OrderInterface) {
            $io->error(sprintf('Nincs "%s" számú rendelés.', $orderNumber));

            return Command::FAILURE;
        }

        $payment = $this->findRefundablePayment($order);

        if (!$payment instanceof PaymentInterface) {
            $io->error(sprintf(
                'A(z) "%s" rendeléshez nincs jóváírható SimplePay fizetés. '
                . 'A fizetésnek "%s" állapotúnak kell lennie.',
                $orderNumber,
                BasePaymentInterface::STATE_COMPLETED,
            ));

            return Command::FAILURE;
        }

        if ($input->getOption('record')) {
            $this->recordingHttpClient->enable();
            $io->note('A nyers HTTP forgalom rögzítése bekapcsolva.');
        }

        try {
            $settings = GatewayConfigReader::read($payment->getMethod() ?? throw new \LogicException(
                'A jóváírható fizetéshez tartoznia kell fizetési módnak.',
            ));

            $syliusAmount = $this->syliusAmount($input, $payment);
            $minorUnits = SyliusAmountConverter::toMinorUnits($syliusAmount, $settings->currency);

            $details = $payment->getDetails();
            $details[Details::REFUND_KEY] = ['amount' => $minorUnits];
            $payment->setDetails($details);

            $io->text(sprintf(
                'Jóváírás indítása: %d %s (a(z) %d. fizetésre).',
                $minorUnits,
                $settings->currency->value,
                (int) $payment->getId(),
            ));

            $this->payum->getGateway((string) $payment->getMethod()?->getCode())->execute(new Refund($payment));

            $this->entityManager->flush();
        } catch (SyliusSimplePayException | \CodeConjure\SimplePay\Exception\SimplePayException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $state = TransactionState::fromArray($this->stateArray($payment->getDetails()));

        $io->success('A jóváírás megtörtént.');
        $io->definitionList(
            ['Jóváírás tranzakcióazonosító' => $state->refundTransactionId ?? '—'],
            ['Jóváírt összeg' => null === $state->refundTotal ? '—' : (string) $state->refundTotal],
            ['Hátralévő összeg' => null === $state->remainingTotal ? '—' : (string) $state->remainingTotal],
        );

        foreach ($this->recordingHttpClient->recordedFiles() as $file) {
            $io->text(sprintf('Rögzítve: %s', $file));
        }

        return Command::SUCCESS;
    }

    private function findRefundablePayment(OrderInterface $order): ?PaymentInterface
    {
        foreach ($order->getPayments() as $payment) {
            if (!$payment instanceof PaymentInterface) {
                continue;
            }

            $method = $payment->getMethod();

            if (null === $method || !GatewayConfigReader::isSimplePay($method)) {
                continue;
            }

            if (BasePaymentInterface::STATE_COMPLETED !== $payment->getState()) {
                continue;
            }

            return $payment;
        }

        return null;
    }

    private function syliusAmount(InputInterface $input, PaymentInterface $payment): int
    {
        $option = $input->getOption('amount');

        if (null === $option) {
            // A teljes összeg kiszámítása ITT történik, nem a RefundAction-ben.
            return $payment->getAmount() ?? throw new \LogicException(
                'A befejezett fizetéshez tartoznia kell összegnek.',
            );
        }

        if (!is_string($option) || 1 !== preg_match('/^-?\d+$/', $option)) {
            throw new \InvalidArgumentException(
                'Az --amount értéke egész szám kell legyen, Sylius-egységben (1/100).',
            );
        }

        return (int) $option;
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
```

> **Az `--amount` Sylius-egységben megy be**, nem forintban. Ez elsőre
> kényelmetlen, de következetes: a Sylius admin mindenhol így számol, és
> egy „forint vagy Sylius-egység?" kétértelműség egy jóváírásnál
> százszoros hibát jelentene. A parancs kiírja az átváltott értéket a
> pénznem alegységében, mielőtt elküldi.

- [ ] **Step 4: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/Command/RefundCommandTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Command tests/Unit/Command
git commit -m "feat(command): konzolos jóváírás rögzítési lehetőséggel

A teljes összeg kiszámítása itt történik, nem a RefundAction-ben — az action
sosem alapértelmez. A --record kapcsoló rögzíti a nyers választ, amivel a
protokoll-csomag jóváírás-nyitottkérdése lezárható."
```

---

### Task 12: `View\SimplePayPaymentView`, admin sablon és fordítások

**Files:**
- Create: `src/View/SimplePayPaymentView.php`, `src/Twig/SimplePayExtension.php`, `src/Resources/views/admin/order_show_payment.html.twig`, `src/Resources/translations/messages.hu.yaml`
- Test: `tests/Unit/View/SimplePayPaymentViewTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePayPayum\Details`, `TransactionState`, `GatewayConfigReader` (Task 5)
- Produces:
  - `SimplePayPaymentView::forPayment(PaymentInterface $payment): ?self`
  - property-k: `$transactionId`, `$status`, `$environment`, `$lastIpnAt`, `$repeatWarning`, `$ipnLog`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/View/SimplePayPaymentViewTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\View;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\View\SimplePayPaymentView;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class SimplePayPaymentViewTest extends TestCase
{
    /** @param array<string, mixed> $details */
    private function payment(array $details, string $factoryName = 'simplepay'): PaymentInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);

        return $payment;
    }

    /** @return array<string, mixed> */
    private static function state(int $repeatCount = 1): array
    {
        return [
            Details::STATE_KEY => [
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'ipnLog' => [[
                    'receivedAt' => '2026-08-31T12:00:00+02:00',
                    'transactionId' => '99844942',
                    'status' => 'FINISHED',
                    'outcome' => 'applied',
                    'repeatCount' => $repeatCount,
                ]],
            ],
        ];
    }

    public function testItReadsTheStateOfASimplePayPayment(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment(self::state()));

        self::assertNotNull($view);
        self::assertSame('99844942', $view->transactionId);
        self::assertSame('FINISHED', $view->status);
        self::assertSame('sandbox', $view->environment);
        self::assertSame('2026-08-31T12:00:00+02:00', $view->lastIpnAt?->format(\DateTimeInterface::ATOM));
    }

    public function testAPaymentOfAnotherGatewayHasNoView(): void
    {
        self::assertNull(SimplePayPaymentView::forPayment($this->payment(self::state(), 'offline')));
    }

    public function testAPaymentWithoutStateStillProducesAViewWithEmptyFields(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment([]));

        self::assertNotNull($view);
        self::assertNull($view->transactionId);
        self::assertNull($view->status);
        self::assertSame([], $view->ipnLog);
        self::assertFalse($view->repeatWarning);
    }

    public function testASingleNotificationRaisesNoWarning(): void
    {
        $view = SimplePayPaymentView::forPayment($this->payment(self::state(repeatCount: 1)));

        self::assertNotNull($view);
        self::assertFalse($view->repeatWarning);
    }

    public function testARepeatedNotificationRaisesTheWarningThatOurConfirmationWasRefused(): void
    {
        // Ez az admin felület egyetlen figyelmeztetése, és a legfontosabb:
        // ha a SimplePay ismétel, a visszaigazolásunkat nem fogadták el.
        $view = SimplePayPaymentView::forPayment($this->payment(self::state(repeatCount: 3)));

        self::assertNotNull($view);
        self::assertTrue($view->repeatWarning);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/View/SimplePayPaymentViewTest.php
```

Elvárt: FAIL, `Class "…\View\SimplePayPaymentView" not found`.

- [ ] **Step 3: A nézet, a sablon és a fordítások megírása**

`src/View/SimplePayPaymentView.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\View;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\IpnLogEntry;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Read-model az admin rendelés-oldalhoz.
 *
 * Ez váltja ki az alkalmazás `Payment` entitásának SimplePay-metódusait:
 * egy entitásnak nem dolga a gateway details-ének értelmezése.
 */
final readonly class SimplePayPaymentView
{
    /** @param list<IpnLogEntry> $ipnLog */
    private function __construct(
        public ?string $transactionId,
        public ?string $status,
        public string $environment,
        public ?\DateTimeImmutable $lastIpnAt,
        public bool $repeatWarning,
        public array $ipnLog,
    ) {
    }

    public static function forPayment(PaymentInterface $payment): ?self
    {
        $method = $payment->getMethod();

        if (null === $method || !GatewayConfigReader::isSimplePay($method)) {
            return null;
        }

        $state = TransactionState::fromArray(self::stateArray($payment->getDetails()));
        $lastEntry = [] === $state->ipnLog ? null : $state->ipnLog[count($state->ipnLog) - 1];

        return new self(
            transactionId: $state->transactionId,
            status: $state->status?->value,
            environment: GatewayConfigReader::read($method)->environment->value,
            lastIpnAt: $lastEntry?->receivedAt,
            // Ha bármelyik bejegyzés ismétlődött, a visszaigazolásunkat
            // nem fogadták el. Ez a felület egyetlen figyelmeztetése.
            repeatWarning: self::hasRepeat($state),
            ipnLog: $state->ipnLog,
        );
    }

    private static function hasRepeat(TransactionState $state): bool
    {
        foreach ($state->ipnLog as $entry) {
            if ($entry->repeatCount > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private static function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
```

`src/Resources/views/admin/order_show_payment.html.twig`:

```twig
{% set order = hookable_metadata.context.order|default(hookable_metadata.context.resource) %}

{% for payment in order.payments %}
    {% set view = simplepay_payment_view(payment) %}

    {% if view %}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ 'codeconjure_simplepay.admin.heading'|trans }}</h5>
            </div>
            <div class="card-body">
                {% if view.repeatWarning %}
                    <div class="alert alert-warning" role="alert">
                        {{ 'codeconjure_simplepay.admin.repeat_warning'|trans }}
                    </div>
                {% endif %}

                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ 'codeconjure_simplepay.admin.transaction_id'|trans }}</dt>
                    <dd class="col-sm-8">{{ view.transactionId ?? '—' }}</dd>

                    <dt class="col-sm-4">{{ 'codeconjure_simplepay.admin.status'|trans }}</dt>
                    <dd class="col-sm-8">{{ view.status ?? '—' }}</dd>

                    <dt class="col-sm-4">{{ 'codeconjure_simplepay.admin.environment'|trans }}</dt>
                    <dd class="col-sm-8">{{ ('codeconjure_simplepay.environment.' ~ view.environment)|trans }}</dd>

                    <dt class="col-sm-4">{{ 'codeconjure_simplepay.admin.last_ipn'|trans }}</dt>
                    <dd class="col-sm-8">
                        {{ view.lastIpnAt ? view.lastIpnAt|date('Y-m-d H:i:s T') : '—' }}
                    </dd>
                </dl>

                {% if view.ipnLog is not empty %}
                    <h6 class="mt-3">{{ 'codeconjure_simplepay.admin.ipn_log'|trans }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ 'codeconjure_simplepay.admin.received_at'|trans }}</th>
                                    <th>{{ 'codeconjure_simplepay.admin.status'|trans }}</th>
                                    <th>{{ 'codeconjure_simplepay.admin.outcome'|trans }}</th>
                                    <th>{{ 'codeconjure_simplepay.admin.repeat_count'|trans }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for entry in view.ipnLog %}
                                    <tr>
                                        <td>{{ entry.receivedAt|date('Y-m-d H:i:s') }}</td>
                                        <td>{{ entry.status.value }}</td>
                                        <td>{{ ('codeconjure_simplepay.outcome.' ~ entry.outcome.value)|trans }}</td>
                                        <td>{{ entry.repeatCount }}</td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                {% endif %}
            </div>
        </div>
    {% endif %}
{% endfor %}
```

A `simplepay_payment_view()` Twig függvényhez kell egy extension.
`src/Twig/SimplePayExtension.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Twig;

use CodeConjure\SyliusSimplePayPlugin\View\SimplePayPaymentView;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SimplePayExtension extends AbstractExtension
{
    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'simplepay_payment_view',
                static fn (PaymentInterface $payment): ?SimplePayPaymentView
                    => SimplePayPaymentView::forPayment($payment),
            ),
        ];
    }
}
```

`src/Resources/translations/messages.hu.yaml`:

```yaml
codeconjure_simplepay:
    form:
        merchant: 'Merchant azonosító'
        merchant_help: 'A SimplePay kereskedői azonosító. Pénznemenként külön azonosító tartozik hozzá.'
        secret_key: 'Titkos kulcs'
        secret_key_help: 'Az aláíráshoz használt kulcs a kereskedői vezérlőpanelről.'
        environment: 'Környezet'
        currency: 'Pénznem'
        currency_help: 'A merchant azonosítóhoz tartozó pénznem. Egy merchant egy pénznemet fogad.'
    environment:
        sandbox: 'Teszt (sandbox)'
        production: 'Éles'
    outcome:
        applied: 'Alkalmazva'
        duplicate: 'Ismétlődés'
        rejected: 'Elutasítva'
    ipn:
        heading: 'Értesítési (IPN) cím'
        instructions: 'Másold be ezt a címet a SimplePay kereskedői vezérlőpanelbe, a „Technikai adatok” menüpont alá. Fiókonként külön kell megadni.'
        after_save: 'Az értesítési cím a fizetési mód mentése után jelenik meg itt, mert a kódját tartalmazza.'
    admin:
        heading: 'SimplePay tranzakció'
        transaction_id: 'Tranzakcióazonosító'
        status: 'Állapot'
        environment: 'Környezet'
        last_ipn: 'Utolsó értesítés'
        ipn_log: 'Értesítési napló'
        received_at: 'Beérkezett'
        outcome: 'Eredmény'
        repeat_count: 'Ismétlés'
        repeat_warning: 'A SimplePay ismételten elküldte ugyanazt az értesítést. Ez azt jelenti, hogy a visszaigazolásunkat nem fogadta el — érdemes ellenőrizni a receiveDate időbélyeg formátumát.'
```

- [ ] **Step 4: Futtasd és commitolj**

```bash
vendor/bin/phpunit tests/Unit/View/SimplePayPaymentViewTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/View src/Twig src/Resources/views/admin/order_show_payment.html.twig src/Resources/translations tests/Unit/View
git commit -m "feat(admin): rendelés-oldali nézet az IPN-naplóval

Read-model, ami kiváltja az alkalmazás Payment entitásának SimplePay-
metódusait — egy entitásnak nem dolga a gateway details-ét értelmezni.
Az ismétlődő értesítés figyelmeztetést kap: az azt jelenti, hogy a
visszaigazolásunkat nem fogadták el."
```

---

### Task 13: Szolgáltatás-bekötés

**Files:**
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/ServiceDefinitionTest.php`

**Interfaces:**
- Consumes: minden korábbi task
- Produces: minden szolgáltatás bekötve, a `payum.action` és `sylius.gateway_configuration_type` tagekkel

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/ServiceDefinitionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit;

use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController;
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
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/ServiceDefinitionTest.php
```

Elvárt: FAIL — a `services.xml` egyelőre üres.

- [ ] **Step 3: A `services.xml` megírása**

`src/Resources/config/services.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <defaults autowire="false" autoconfigure="false" public="false"/>

        <!-- A payum.action tag "factory" attribútuma korlátozza az actiont
             a simplepay gateway-re. Enélkül MINDEN gateway megkapná a
             Convert actionünket, és egy PayPal fizetés is SimplePay
             payloaddá alakulna. -->
        <service id="CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction">
            <argument type="service" id="router"/>
            <tag name="payum.action" factory="simplepay"/>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType">
            <tag name="sylius.gateway_configuration_type" type="simplepay" label="SimplePay"/>
            <tag name="form.type"/>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient">
            <argument type="service" id="Psr\Http\Client\ClientInterface"/>
            <argument>%kernel.project_dir%/var/simplepay</argument>
            <argument>false</argument>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Controller\IpnController" public="true">
            <argument type="service" id="sylius.repository.payment_method"/>
            <argument type="service" id="sylius.repository.payment"/>
            <argument type="service" id="payum"/>
            <argument type="service" id="doctrine.orm.entity_manager"/>
            <argument type="service" id="payum.reply_to_symfony_response_converter"/>
            <argument type="service" id="logger" on-invalid="null"/>
            <tag name="monolog.logger" channel="simplepay"/>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController" public="true">
            <argument type="service" id="payum"/>
            <argument type="service" id="doctrine.orm.entity_manager"/>
            <argument type="service" id="logger" on-invalid="null"/>
            <tag name="monolog.logger" channel="simplepay"/>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand">
            <argument type="service" id="sylius.repository.order"/>
            <argument type="service" id="payum"/>
            <argument type="service" id="doctrine.orm.entity_manager"/>
            <argument type="service" id="CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient"/>
            <tag name="console.command"/>
        </service>

        <service id="CodeConjure\SyliusSimplePayPlugin\Twig\SimplePayExtension">
            <tag name="twig.extension"/>
        </service>
    </services>
</container>
```

> **A `%kernel.project_dir%/var/simplepay` könyvtárat a `RecordingHttpClient`
> nem hozza létre.** Az első `--record` futtatás előtt kézzel kell:
> `mkdir -p var/simplepay`. Szándékos: egy csendben létrehozott könyvtár
> éles környezetben elrejtené, hogy érzékeny adat kerül lemezre.
>
> **A `Psr\Http\Client\ClientInterface` szolgáltatás-azonosító** az
> alkalmazásban a `symfony/http-client` `Psr18Client`-jére kell mutasson.
> Ha nincs ilyen alias, a Task 15 veszi fel az app `services.yaml`-jába.

- [ ] **Step 4: Futtasd és commitolj**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add src/Resources/config/services.xml tests/Unit/ServiceDefinitionTest.php
git commit -m "feat(di): a plugin szolgáltatásainak bekötése

A payum.action tag factory attribútuma a simplepay gateway-re korlátozza a
Convert actiont — enélkül minden gateway megkapná, és egy PayPal fizetés is
SimplePay payloaddá alakulna."
```

---

### Task 14: README és a függőség-feloldás lezárása

**Files:**
- Create: `README.md`
- Modify: `composer.json`

**Interfaces:**
- Consumes: minden korábbi task
- Produces: telepíthető, dokumentált plugin

- [ ] **Step 1: A `composer.json` függőség-feloldásának lezárása**

Ugyanaz a döntés, mint a Payum csomagnál: ha a két `codeconjure/*` csomag
felkerült a Packagistra, semmi teendő. Ha nem, VCS repository kell:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/connorhu/simplepay-lib" },
    { "type": "vcs", "url": "https://github.com/connorhu/simplepay-payum" }
],
"minimum-stability": "dev",
"prefer-stable": true
```

és a kikötések `dev-main`-re. **Path repository a commitolt fájlba nem
kerülhet** — az csak a fejlesztő gépén működik, és a CI-t némán elrontja.

- [ ] **Step 2: A README megírása**

`README.md`:

````markdown
# codeconjure/simplepay-sylius-plugin

Sylius plugin az OTP SimplePay v2 fizetéshez.

A három rétegre bontott integráció felső rétege:

- `codeconjure/simplepay` — protokoll: aláírás, endpointok, DTO-k, IPN
- `codeconjure/simplepay-payum` — Payum actionök és gateway factory
- **`codeconjure/simplepay-sylius-plugin`** — ez a csomag

## Telepítés

```bash
composer require codeconjure/simplepay-sylius-plugin
```

`config/bundles.php`:

```php
CodeConjure\SimplePayPayum\Bundle\SimplePayPayumBundle::class => ['all' => true],
CodeConjure\SyliusSimplePayPlugin\CodeConjureSyliusSimplePayPlugin::class => ['all' => true],
```

`config/routes/codeconjure_simplepay.yaml`:

```yaml
codeconjure_simplepay:
    resource: '@CodeConjureSyliusSimplePayPlugin/Resources/config/routing.yaml'
```

> **A plugin útvonalait a shop locale-prefixén KÍVÜL kell importálni.** Az
> IPN-cím a SimplePay vezérlőpanelbe kerül, ahol egy `/{_locale}` szegmens
> törékeny és félrevezető volna.

A protokoll-kliens PSR-18 és PSR-17 implementációt vár. Ha az alkalmazásban
nincs `Psr\Http\Client\ClientInterface` alias:

```yaml
# config/services.yaml
services:
    Psr\Http\Client\ClientInterface: '@Symfony\Component\HttpClient\Psr18Client'
    Psr\Http\Message\RequestFactoryInterface: '@nyholm.psr7.psr17_factory'
    Psr\Http\Message\StreamFactoryInterface: '@nyholm.psr7.psr17_factory'
```

## Beállítás

Az adminban hozz létre egy fizetési módot, gateway-nek a **SimplePay**-t
választva. Négy mező:

| mező | érték |
|---|---|
| Merchant azonosító | a SimplePay kereskedői azonosító |
| Titkos kulcs | a hozzá tartozó aláíró kulcs |
| Környezet | teszt (sandbox) vagy éles |
| Pénznem | HUF, EUR vagy USD |

**A pénznem merchant-hez kötött:** egy SimplePay merchant azonosító egy
pénznemet fogad. Több pénznemhez több merchant és több fizetési mód kell.
Ha a rendelés pénzneme nem egyezik a beállítottal, a plugin hangosan
elbukik, mielőtt a kérés kimenne.

### Az értesítési (IPN) cím

Mentés után a fizetési mód szerkesztő oldalán megjelenik a bemásolandó cím:

```
https://a-boltod.hu/payment/simplepay/ipn/{fizetesi-mod-kodja}
```

Ezt a SimplePay kereskedői vezérlőpanelen, a **„Technikai adatok"**
menüpont alatt kell megadni, **fiókonként külön**. Per-kérésben nincs
IPN-cím mező — a `start` kérés ezt nem tudja befolyásolni.

## Hogyan dönti el a plugin a fizetés állapotát

| forrás | szerep |
|---|---|
| `r`/`s` visszatérési paraméterek | **tájékoztató, sosem dönt** — csak naplózódik |
| `/query` (Payum `Sync`) | a visszatéréskor ez dönti el az állapotot |
| IPN | aszinkron, ugyanazt az állapotgépet hajtja |

Az `r` aláírt, tehát nem hamisítható — de csak azt mondja meg, **mit lát a
vásárló**, nem azt, hogy a pénz megérkezett-e.

## Jóváírás

```bash
bin/console simplepay:refund <rendelésszám>
bin/console simplepay:refund <rendelésszám> --amount=50000
bin/console simplepay:refund <rendelésszám> --record
```

Az `--amount` **Sylius-egységben** megy be (1/100), ahogy a Sylius admin
mindenhol számol. A parancs kiírja az átváltott értéket a pénznem
alegységében, mielőtt elküldi.

A `--record` a nyers HTTP kérést és választ `var/simplepay/` alá menti.
A könyvtárat előbb létre kell hozni (`mkdir -p var/simplepay`) — szándékosan
nem jön létre magától, mert bekapcsolva a vevő neve, e-mail címe és
számlázási címe is lemezre kerül.

## Az admin rendelés-oldalon

A SimplePay fizetéseknél megjelenik a tranzakcióazonosító, az állapot, a
környezet és az **értesítési napló**.

**Ha a naplóban ismétlődés látszik** (`Ismétlés` oszlop 1-nél nagyobb), a
plugin figyelmeztetést mutat. Ez azt jelenti, hogy a SimplePay nem fogadta
el az aláírt visszaigazolásunkat, és újraküldi az értesítést. A jelenleg
egyetlen ismert gyanúsított a `receiveDate` időbélyeg formátuma — lásd a
`codeconjure/simplepay-payum` README „Ismert bizonytalanságok" szakaszát.

## Amit ez a plugin nem tud

Kétlépéses fizetés, ismétlődő fizetés, tárolt kártya, `WIRE` (átutalásos)
fizetési mód, admin jóváírás-felület, `refunds: true` lekérdezés.

A `WIRE` azért marad ki, mert élőben sosem lett kipróbálva, és az átutalásos
folyamat — ami a beérkezésig nyitva marad — állapotkezeléséről egyik csomag
sem modellez semmit.

## Tesztelés

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

A plugin **nem hoz saját Sylius teszt-kernelt**: az egységtesztek a
leképezésre, az összeg-átváltásra és a hivatkozás-sémára koncentrálnak,
az end-to-end lefedés pedig a befogadó alkalmazás tesztjeiben készül.

## Licenc

MIT
````

- [ ] **Step 3: Ellenőrzés és commit**

```bash
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
git add README.md composer.json
git commit -m "docs: README és a függőség-feloldás lezárása

A README kimondja, mit dönt el az állapot (query és IPN, sosem az r/s),
hogy az IPN-címet a vezérlőpanelbe kell bemásolni, és mit jelent az
ismétlődés az értesítési naplóban."
git push origin main
```

---

### Task 15: Az alkalmazás bekötése és a régi implementáció eltakarítása

**Files (a boltban, `/server/www/egyhazzene.hu/bolt`):**
- Modify: `composer.json`, `config/bundles.php`, `config/services.yaml`, `config/packages/_sylius.yaml`, `src/Entity/Payment/Payment.php`, `translations/messages.hu.yaml`, `.env.local`
- Create: `config/routes/codeconjure_simplepay.yaml`
- Delete: `src/Components/Payum/SimplePay/`, `src/Controller/Shop/SimplePayReturnController.php`, `templates/shop/payment/simplepay_return.html.twig`, `templates/admin/payment_method/form/sections/gateway_configuration/simplepay.html.twig`, `templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig`, `tests/Unit/Components/Payum/SimplePay/`

**Interfaces:**
- Consumes: a kész, zölden futó plugin
- Produces: működő SimplePay fizetés a boltban, a régi kód nélkül

> **EZ AZ EGYETLEN TASK, AMI A BOLT FORRÁSÁHOZ NYÚL.** Csak akkor kezdd el,
> ha a plugin minden korábbi taskja zölden fut.

- [ ] **Step 1: A plugin bekötése — a régi kód még a helyén**

A boltban:

```bash
cd /server/www/egyhazzene.hu/bolt
composer config repositories.simplepay path ../incubator/simplepay
composer config repositories.simplepay-payum path ../incubator/simplepay-payum
composer config repositories.simplepay-sylius-plugin path ../incubator/simplepay-sylius-plugin
composer require codeconjure/simplepay-sylius-plugin
composer require symfony/http-client nyholm/psr7
```

Az utolsó parancs a `require-dev`-ből a `require`-be mozgatja a két
csomagot — élesben kell a PSR-18 kliens.

`config/bundles.php`, a lista végére:

```php
CodeConjure\SimplePayPayum\Bundle\SimplePayPayumBundle::class => ['all' => true],
CodeConjure\SyliusSimplePayPlugin\CodeConjureSyliusSimplePayPlugin::class => ['all' => true],
```

`config/routes/codeconjure_simplepay.yaml`:

```yaml
codeconjure_simplepay:
    resource: '@CodeConjureSyliusSimplePayPlugin/Resources/config/routing.yaml'
```

`config/services.yaml`, a `services:` blokkba:

```yaml
    Psr\Http\Client\ClientInterface: '@Symfony\Component\HttpClient\Psr18Client'
    Psr\Http\Message\RequestFactoryInterface: '@nyholm.psr7.psr17_factory'
    Psr\Http\Message\StreamFactoryInterface: '@nyholm.psr7.psr17_factory'
```

Ellenőrzés — a konténer felépül, és mindkét gateway ott van:

```bash
bin/console cache:clear
bin/console debug:container --tag=payum.gateway_factory_builder
bin/console debug:container --tag=sylius.gateway_configuration_type
bin/console debug:router | grep simplepay
```

Elvárt: a `simplepay` factory **kétszer** szerepel — a régi
`App\Components\...\SimplePayGatewayFactoryBuilder` és az új plugin is
regisztrálja. **Ez átmenetileg rendben van**, a következő lépés oldja fel.

- [ ] **Step 2: A régi kód törlése**

```bash
cd /server/www/egyhazzene.hu/bolt
git rm -r --quiet \
    src/Components/Payum/SimplePay \
    src/Controller/Shop/SimplePayReturnController.php \
    templates/shop/payment/simplepay_return.html.twig \
    templates/admin/payment_method/form/sections/gateway_configuration/simplepay.html.twig \
    templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig \
    tests/Unit/Components/Payum/SimplePay
```

> **A `templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig`
> teljes egészében törlődik.** Az első sora `{% include '@!SyliusAdmin/...' %}`,
> vagyis csak azért létezett, hogy a SimplePay blokkot hozzáfűzze a Sylius
> sajátjához. A plugin most a saját hook-template-jét hozza, tehát ez a
> felülírás fölöslegessé vált. **Ellenőrizd a törlés előtt**, hogy tényleg
> csak SimplePay-specifikus tartalom van benne:
> ```bash
> git show HEAD:templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig
> ```

- [ ] **Step 3: A konfigurációs maradványok eltakarítása**

`config/packages/_sylius.yaml` — töröld a két hook-bejegyzést (a
561–567. sor környékén):

```yaml
        'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.simplepay':
            simplepay_configuration:
                template: 'admin/payment_method/form/sections/gateway_configuration/simplepay.html.twig'

        'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.simplepay':
            simplepay_configuration:
                template: 'admin/payment_method/form/sections/gateway_configuration/simplepay.html.twig'
```

és vedd fel helyettük a plugin sablonjait:

```yaml
        'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.simplepay':
            simplepay_configuration:
                template: '@CodeConjureSyliusSimplePayPlugin/admin/gateway_configuration.html.twig'

        'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.simplepay':
            simplepay_configuration:
                template: '@CodeConjureSyliusSimplePayPlugin/admin/gateway_configuration.html.twig'

        'sylius_admin.order.show.content.sections.payments':
            simplepay_transaction:
                template: '@CodeConjureSyliusSimplePayPlugin/admin/order_show_payment.html.twig'
                priority: -10
```

> **A rendelés-oldali hook nevét ellenőrizni kell**, mert a fenti
> (`sylius_admin.order.show.content.sections.payments`) feltételezés.
> Futtasd: `bin/console debug:hooks | grep 'order.show'`, és használd a
> valódi nevet. Ha nincs megfelelő hook, a sablon a
> `templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig`
> felülírásból is behúzható — de akkor azt a fájlt ne töröld, hanem írd át
> úgy, hogy a plugin sablonját inkludálja.

`src/Entity/Payment/Payment.php` — az öt SimplePay-metódus és a
`stringDetail()` segéd törlése; az osztály üres leszármazottá válik:

```php
<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Payment as BasePayment;

/**
 * A SimplePay-specifikus olvasók a
 * codeconjure/simplepay-sylius-plugin `SimplePayPaymentView` szolgáltatásába
 * kerültek: egy entitásnak nem dolga a gateway details tömbjének értelmezése.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment')]
class Payment extends BasePayment
{
}
```

`translations/messages.hu.yaml` — a `simplepay:` blokk törlése (a 98. sor
környékén); a fordítások a pluginba költöztek.

`.env.local` — a `SIMPLEPAY_MERCHANT_ID` és `SIMPLEPAY_SECRET_KEY` sorok
törlése. **Ezeket semmi nem olvassa** a `src/`-ben és a `config/`-ban;
a merchant azonosító és a titok az admin gateway konfigurációba kerül.

Ellenőrizd, hogy tényleg nincs használatuk:

```bash
grep -rn "SIMPLEPAY_" src/ config/ templates/ || echo "nincs használat"
```

- [ ] **Step 4: Ellenőrzés**

```bash
bin/console cache:clear
bin/console lint:container
bin/console debug:container --tag=payum.gateway_factory_builder
bin/console debug:router | grep simplepay
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Elvárt:
- a `simplepay` factory **egyszer** szerepel, a plugin bejegyzésével
- két útvonal: `codeconjure_simplepay_ipn` és `codeconjure_simplepay_return`
- a `grep -rn "App\\\\Components\\\\Payum" src/ config/` semmit nem talál
- a bolt tesztjei és a phpstan zöldek

Ha a phpstan a törölt `Payment` metódusokra hivatkozó Twig sablonokra
panaszkodik, keresd meg őket:

```bash
grep -rn "simplePay" templates/
```

- [ ] **Step 5: Kézi végigvitel**

```bash
bin/console cache:clear --env=prod
```

1. Az adminban hozz létre egy SimplePay fizetési módot, töltsd ki a négy
   mezőt, mentsd el.
2. Nyisd meg újra, és másold ki a megjelenő IPN-URL-t.
3. Állítsd be a SimplePay vezérlőpanelen, a „Technikai adatok" alatt.
4. Vigyél végig egy rendelést a bolton.
5. A rendelés admin oldalán ellenőrizd: van tranzakcióazonosító, az állapot
   `FINISHED`, és az **értesítési naplóban egyetlen bejegyzés van
   `repeatCount: 1` értékkel.**

> **Ha a `repeatCount` 1-nél nagyobb**, a SimplePay nem fogadta el az aláírt
> visszaigazolásunkat, és a legvalószínűbb ok a `receiveDate` időbélyeg
> formátuma (`+02:00` vs. `+0200`). A javítás egy konstans a protokoll-
> csomagban (`Client::RECEIVE_DATE_FORMAT`), **külön PR-ben**, saját
> indoklással — és akkor mérésünk lesz, nem következtetésünk.

6. `bin/console simplepay:refund <rendelésszám> --record` — a `var/simplepay/`
   alá kerülő nyers válasz a protokoll-csomag jóváírás-fixture-jévé válik,
   **szintén külön PR-ben**, az érzékeny mezők `"[REDACTED]"`-re cserélve,
   de a kulcsokat megtartva.

- [ ] **Step 6: Commit**

```bash
cd /server/www/egyhazzene.hu/bolt
git add -A
git commit -m "refactor(payment): a SimplePay integráció csomagokba költöztetése

Az App\Components\Payum\SimplePay névtér (18 fájl, ~2270 sor), a visszatérési
controller, a két sablon és a teljes tesztkönyvtár törölve; helyükre a
codeconjure/simplepay-payum és codeconjure/simplepay-sylius-plugin csomagok
lépnek.

A Payment entitásból kikerültek a SimplePay-olvasók: egy entitásnak nem
dolga a gateway details tömbjének értelmezése. A .env.local SIMPLEPAY_*
változói törölve — semmi nem olvasta őket.

Adatmigráció nincs: élesben nincs forgalom, a payment.details alakja
szabadon törhető."
```

---

## Önellenőrzés — spec-lefedettség

| Spec fejezet | Task |
|---|---|
| 2. csomag-azonosítók, függőségek | Task 1, 14 |
| 3. fájlszerkezet | Fájlszerkezet táblázat |
| 4.1 `orderRef` séma | Task 3 |
| 4.2 pénzátváltás | Task 2 |
| 4.3 pénznem-őr | Task 5, 6 |
| 4.4 a többi mező, ISO ország, fix CARD | Task 6 |
| 4.5 `urls` | Task 6 |
| 5. admin űrlap, IPN-súgó | Task 7 |
| 6. IPN-végpont | Task 8 |
| 6.1 miért működik a `Notify($payment)` | Task 8 (a `flush()` a reply elkapása után) |
| 6.2 miért nem a `PaymentRequest` út | Task 8 (osztály-dokumentáció) |
| 7. visszatérési végpont | Task 9 |
| 8. refund parancs | Task 11 |
| 8.1 `RecordingHttpClient` | Task 10 |
| 9. admin rendelés-oldal | Task 12 |
| 10. tesztstratégia | minden task 1. és 4. lépése |
| 11. az alkalmazás takarítása | Task 15 |
| 12. a mérés | Task 15, 5. lépés |
| 13. elfogadási kritériumok | lásd lent |
| 14. hatókörön kívül | nincs task — szándékosan |

## Önellenőrzés — elfogadási kritériumok

| Kritérium | Hol bizonyul |
|---|---|
| 1. checkout végigmegy | Task 15, 5. lépés (kézi), `ConvertPaymentActionTest` (leképezés) |
| 2. a vevő a Sylius standard oldalát látja, az állapot `query()`-ből | `ReturnControllerTest::testItSyncsBeforeItReadsTheStatus`, `…testItRedirectsToTheTokenAfterUrl…` |
| 3. aláírt IPN 200-as aláírt visszaigazolást kap | `IpnControllerTest::testAnAuthenticatedNotificationIsAnsweredWithTheSignedConfirmation` |
| 4. az admin kiírja a bemásolandó IPN-URL-t | Task 7 sablonja; Task 15, 5. lépés, 2. pont |
| 5. `simplepay:refund --record` valódi jóváírást indít és rögzít | `RefundCommandTest`, `RecordingHttpClientTest`; Task 15, 5. lépés, 6. pont |
| 6. phpstan level 9 és ECS tisztán | minden task ellenőrző lépése |
| 7. a takarítás minden sora elvégezve, a bolt tesztjei zöldek | Task 15, 4. lépés |

## Nyitott döntések, amiket az implementer hoz meg

Mindhárom szándékosan marad nyitva: a válasz csak a kód írása közben derül ki,
és a terv jobban jár egy megnevezett kérdéssel, mint egy kitalált válasszal.

1. **Task 4** — `LocaleToLanguageMap::resolve()` statikus vagy példány-metódus.
   Ajánlás: csak statikus, YAGNI.
2. **Task 8** — ismeretlen rendelésnél mi legyen a `Notify` modellje. Ajánlás:
   eldobható `\ArrayObject`, és a teszt `assertCount` értékének igazítása.
3. **Task 15, 3. lépés** — a rendelés-oldali admin hook pontos neve. A
   `debug:hooks` mondja meg; ha nincs megfelelő, a `_payment.html.twig`
   felülírás marad, a plugin sablonját inkludálva.
