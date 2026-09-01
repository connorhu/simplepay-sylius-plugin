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
    resource: '@CodeConjureSyliusSimplePayPlugin/src/Resources/config/routing.yaml'
```

> A bundle `getPath()`-ja a csomag gyökerét adja vissza, a `Resources/`
> mappa pedig a `src/` alatt van — az útvonal-erőforrás importjában emiatt
> kell a `src/` szegmens. Mérve: `Kernel::locateResource()` a `Resources/`
> szegmenssel `Unable to find file` hibával elszáll, a `src/Resources/`
> szegmenssel viszont megtalálja a fájlt.

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
mindenhol számol. Nullát vagy negatív értéket a parancs elutasít. A parancs
kiírja az átváltott értéket a pénznem alegységében, mielőtt elküldi.

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

## Ismert korlátok

**A részleges jóváírás Payum felé teljesként látszik.** A
`simplepay:refund --amount=…` valódi részösszeget ír a SimplePay felé, és a
SimplePay helyesen is dolgozza fel — de a `codeconjure/simplepay-payum`
`StatusMap`-je a `REFUND` státuszt a `remainingTotal` figyelmen kívül
hagyásával `markRefunded()`-re képezi. A Sylius fizetés tehát **teljesen
jóváírtnak** látszik akkor is, ha csak egy részét írtuk jóvá. Ráadásul a
`REFUND` státusz után a Payum-csomag átmenet-őre minden későbbi `Sync`
státuszváltást visszautasít, a `RefundAction` pedig a `refundTotal`-t
hívásonként felülírja, nem halmozza — **két részleges jóváírás után a details
az utolsó összeget mutatja, nem az összesítettet.**

Ez a Payum-réteg tervezési következménye, nem ennek a pluginnak a hibája: az őr
szigorúsága szándékos. A `simplepay:refund` viszont elfogad részösszeget, tehát
**itt válik láthatóvá**. Amíg nem rendezzük, a részleges jóváírás valódi
elszámolása a SimplePay kereskedői panelben látszik, nem a Syliusban.

**Ha egy már kifizetett rendelés fizetési oldalát töltik újra**, a
`CaptureAction` `PaymentAlreadySettledException`-t dob a dupla terhelés ellen.
A plugin ezt elkapja, lekérdezi az igazi állapotot, és a vevőt a Sylius
szokásos oldalára viszi — hibaoldal helyett.

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
