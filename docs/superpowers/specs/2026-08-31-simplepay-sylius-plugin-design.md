# `codeconjure/simplepay-sylius-plugin` — tervdokumentum

| | |
|---|---|
| Dátum | 2026-08-31 |
| Állapot | jóváhagyva, implementációra vár |
| Fázis | 2. fázis, második csomag |
| Repo | https://github.com/connorhu/simplepay-sylius-plugin (létrehozandó) |
| Előzmény | `codeconjure/simplepay` (1. fázis), `codeconjure/simplepay-payum` (ugyanez a fázis) |

---

## 1. Cél

Sylius plugin a SimplePay v2 fizetéshez: admin gateway űrlap, `Payment` → SimplePay payload
leképezés, IPN-végpont, visszatérési végpont, jóváírás konzol parancs.

Ez a három rétegre bontott integráció felső rétege. **Itt él minden, ami rendelést,
admin felületet vagy Sylius állapotgépet ismer** — és csak itt.

```
codeconjure/simplepay                 protokoll
codeconjure/simplepay-payum           Payum actionök, gateway factory
codeconjure/simplepay-sylius-plugin   ← ez a csomag
```

## 2. Csomag-azonosítók és függőségek

| | |
|---|---|
| Név | `codeconjure/simplepay-sylius-plugin` |
| Namespace | `CodeConjure\SyliusSimplePayPlugin\` |
| Licenc | MIT |
| PHP | `^8.4` |

```json
"require": {
    "php": "^8.4",
    "codeconjure/simplepay": "^1.0",
    "codeconjure/simplepay-payum": "^1.0",
    "sylius/sylius": "^2.1",
    "symfony/http-client": "^7.2",
    "nyholm/psr7": "^1.8"
}
```

A `symfony/http-client` és a `nyholm/psr7` itt **éles függőség**, nem dev: a protokoll-kliens
PSR-18 és PSR-17 implementációt vár, és ezt a legfelső réteg biztosítja.

## 3. Fájlstruktúra

```
src/
    CodeConjureSyliusSimplePayPlugin.php
    DependencyInjection/…
    Action/ConvertPaymentAction.php
    Money/SyliusAmountConverter.php
    Order/OrderReferenceFactory.php
    Order/OrderReferenceParser.php
    Language/LocaleToLanguageMap.php
    Form/Type/SimplePayGatewayConfigurationType.php
    Controller/IpnController.php
    Controller/ReturnController.php
    Command/RefundCommand.php
    Debug/RecordingHttpClient.php
    View/SimplePayPaymentView.php
    Resources/config/services.xml
    Resources/config/routing.yaml
    Resources/views/admin/gateway_configuration.html.twig
    Resources/views/admin/order_show_payment.html.twig
    Resources/translations/messages.hu.yaml
tests/Unit/…
```

## 4. `ConvertPaymentAction`

A Payum `Convert` requestet szolgálja ki: Sylius `PaymentInterface` → `simplepay_request`
tömb. Ez a csomag legkényesebb osztálya.

### 4.1 `orderRef`

```
{rendelésszám}-{paymentId}-{próbálkozás}
```

Jobbról parse-olva: az utolsó két kötőjeles szegmens egész szám, az azelőtti minden a
rendelésszám (ami maga is tartalmazhat kötőjelet).

**Miért nem csak a rendelésszám, mint a régi implementációban:** a Sylius sikertelen fizetés
után új `Payment` entitást hoz létre ugyanahhoz a rendeléshez, és egy lejárt tranzakció
újraindítása is új hivatkozást igényel. Közös `orderRef` mellett a
`QueryResponse::byOrderRef()` az **első** találatot adja vissza — néma keveredés két
tranzakció között. A rendelésszám elöl marad, hogy a SimplePay kereskedői panelben
felismerhető legyen.

A `próbálkozás` értékét a Convert **javasolja** (`simplepay_state.attempt + 1`), és a
`CaptureAction` **veszi át** a `simplepay_state`-be — de csak akkor, ha ténylegesen elindított
egy új tranzakciót. Ha a Capture újrahasznosítja a még élő tranzakciót, a javasolt érték
eldobódik, és a következő Convert ugyanazt javasolja újra. Így a számláló pontosan a valóban
elindított tranzakciókat számolja.

> **Nyitott kérdés.** Az `orderRef` **maximális hosszát** az 1. fázis nem rögzítette.
> Az implementáció első feladata kikeresni a hivatalos PDF-ből
> (`PaymentService_SimplePay_2.x_Payment_HU_260504.pdf`). Ha nincs benne, **dokumentált
> ismeretlen marad**, és a plugin nem csonkít — inkább hangosan elbukik. A „nem találtam meg
> a dokumentumban" teljes értékű válasz.

### 4.2 Pénzátváltás — `SyliusAmountConverter`

A Sylius pénznemtől függetlenül 1/100 egységben tárol (`Payment::getAmount(): ?int`).
A `Money::fromMinorUnits()` a **pénznem valódi kitevője** szerinti alegységet várja.

```php
$divisor = 10 ** (2 - $currency->exponent());   // HUF: 100,  EUR/USD: 1

if (0 !== $amount % $divisor) {
    throw new UnrepresentableAmountException(...);
}

return Money::fromMinorUnits(intdiv($amount, $divisor), $currency);
```

| pénznem | kitevő | Sylius érték | osztó | SimplePay alegység |
|---|---|---|---|---|
| HUF | 0 | 100000 | 100 | 1000 (Ft) |
| EUR | 2 | 1000 | 1 | 1000 (cent) |
| USD | 2 | 1000 | 1 | 1000 (cent) |

**Soha nem kerekít.** Egy 100050-es HUF érték (1000,50 Ft) nem ábrázolható SimplePay-ben;
ilyenkor hangos hiba jár, nem néma kerekítés. A Sylius ezt normál működésben nem állítja elő,
de nem is tiltja — és a csendes kerekítés pénzügyi hiba.

A régi implementáció minden pénznemre két tizedessel osztott 100-zal, ami HUF-nál
**százszoros eltérés** lett volna.

Ez az osztály önálló tesztosztályt kap: mindhárom pénznem, a nulla, a negatív érték
(jóváírásnál előfordul) és az oszthatatlan eset.

### 4.3 Pénznem-őr

A gateway konfigban van egy `currency` mező, mert a **SimplePay merchant azonosító
pénznemhez kötött**: egy merchant egy pénznemet fogad, több pénznemhez több merchant kell.

A Convert összeveti a konfigurált pénznemet a fizetés `getCurrencyCode()` értékével.
Eltérés → hangos `ConfigurationException`, ami mindkét értéket megnevezi. Enélkül a kérés
kimenne, és a SimplePay egy nehezen értelmezhető hibakóddal utasítaná el.

### 4.4 A többi mező

| mező | forrás | hiány esetén |
|---|---|---|
| `customerEmail` | `$order->getCustomer()?->getEmail()` | hangos hiba |
| `invoice.name` | `$billingAddress->getFullName()` | hangos hiba |
| `invoice.country` | `$billingAddress->getCountryCode()` | hangos hiba |
| `invoice.city`, `zip`, `address` | számlázási cím | hangos hiba |
| `language` | `LocaleToLanguageMap` a rendelés locale-jából | hangos hiba |
| `methods` | fixen `[PaymentMethod::Card]` | — |
| `urls` | a visszatérési route négy változata | — |

**`invoice.country` ISO kód**, nem szöveges országnév. Az 1. fázis élő kontraktus-tesztje
`'HU'`-t küldött, és a SimplePay elfogadta. A régi implementáció ezzel ellentétes megjegyzése
(„country name of the country given in text") méretlen feltevés volt.

**`methods` fixen `CARD`.** A `WIRE` élőben sosem lett kipróbálva, és az átutalásos folyamat
állapotkezelését egyik csomag sem modellezi. Nem adunk kapcsolót olyasmihez, aminek a
viselkedését nem mértük.

**`LocaleToLanguageMap`:** `hu_*` → `HU`, `en_*` → `EN`, `de_*` → `DE`. Nem szereplő locale →
hangos hiba. A bolt ma egyetlen locale-t használ (`hu_HU`), tehát ez nem sülhet el ma;
egy jövőbeli locale hozzáadásakor viszont **azonnal látszik**, hogy a fizetőoldal nyelvéről
dönteni kell, ahelyett hogy csendben magyar oldalt kapna egy német vevő.

### 4.5 `urls`

Mind a négy cím a plugin visszatérési route-jára mutat, `payum_token`-nel és egy
esemény-jelzővel:

```
/payment/simplepay/return?payum_token={token}&e=success   (fail, cancel, timeout)
```

A protokoll-csomag mindig a differenciált `urls` formát küldi, sosem a string `url`-t.

## 5. Admin gateway űrlap

| mező | típus | megjegyzés |
|---|---|---|
| `merchant` | text, NotBlank | |
| `secretKey` | text, NotBlank | |
| `environment` | choice: `sandbox` \| `production` | a régi bool `sandbox` helyett |
| `currency` | choice: `HUF` \| `EUR` \| `USD` | merchant-hez kötött, lásd 4.3 |
| IPN-URL | csak olvasható | a vezérlőpanelbe másolandó cím |

Bekötés:
`#[AutoconfigureTag(name: 'sylius.gateway_configuration_type', attributes: ['type' => 'simplepay', 'label' => 'SimplePay'])]`

**Az `environment` a régi bool helyett választás.** Egy `sandbox: false` érték nem mondja meg,
hogy éles vagy „nem tudjuk"; az enum igen.

**Az IPN-URL sor.** Kiírja a `/payment/simplepay/ipn/{kód}` teljes címét, hogy be lehessen
másolni a SimplePay vezérlőpanelbe, a „Technikai adatok" menüpont alá. **Per-kérésben nincs
IPN-cím mező** — ez a beállítás fiókonként történik, a `start` kérés nem tudja befolyásolni.

A **create** űrlapon a payment method kódja még nem létezik, ezért ott az URL sem. A mező
ilyenkor ezt írja ki, nem talál ki egy címet.

A régi űrlap `locale` és `allowed_currencies` mezői **megszűnnek**: a nyelv a rendelésből jön,
a pénznem pedig merchant-hez kötött, nem lista.

## 6. IPN-végpont

```
POST /payment/simplepay/ipn/{code}
```

A payment method kódja az útvonalban van, a shop locale-prefixén **kívül**. Ezzel eltűnik a
„melyik merchanthez tartozik ez az üzenet?" tojás-tyúk kérdés: nem kell az aláíratlan
törzsből olvasnunk semmit ahhoz, hogy megtaláljuk a hitelesítéshez szükséges titkot.

Folyamat:

1. Payment method a kód alapján. Nincs meg, vagy a gateway factory neve nem `simplepay` → 404.
2. Nyers HTTP törzs és `Signature` fejléc kiolvasása.
3. `$gateway->execute(new ResolveSimplePayIpn($rawBody, $signature))` — ellenőriz és parse-ol,
   modell nélkül. Innentől az `orderRef` megbízható adat.
4. `OrderReferenceParser` → `paymentId` → `Payment` betöltése, a rendelésszám keresztellenőrzésével.
5. **Ha nincs meg a fizetés:** aláírt visszaigazolás **mégis** megy, `error` szintű naplóval.
   Egy nem létező rendelésért a SimplePay különben örökké ismételne.
6. `$gateway->execute(new Notify($payment))` → átmenet-őr, `simplepay_state` frissítés,
   `HttpResponse` reply az aláírt visszaigazolással.
7. A controller elkapja a reply-t, `flush()`-ol, és visszaadja a Symfony választ.

Aláírás-hiba vagy idegen merchant → 400, naplózva, **soha nem újrapróbálandó**.

### 6.1 Miért működik a `Notify($payment)` entitással

A Sylius `ExecuteSameRequestWithPaymentDetailsAction` **minden gateway-re** be van kötve
(`payum.action` tag, `all="true"`). Bármely `Generic` requestet, aminek a modellje Sylius
`PaymentInterface`, kicsomagol a `details` `ArrayObject`-jébe, újrafuttat, és a details-t
`finally` ágban visszaírja a fizetésre.

A `finally` itt kulcsfontosságú: a `NotifyAction` `HttpResponse` reply-t **dob**, tehát az
állapotfrissítés kivétel-úton hagyja el az actiont. A visszaírás enélkül elveszne — egy
sikeresen feldolgozott IPN nem hagyna nyomot. Nem kell külön gondoskodnunk róla, de tesztnek
kell rögzítenie, hogy tényleg megtörténik.

### 6.2 Miért nem a Sylius `PaymentRequest` notify útja

A Sylius 2.1 kínál egy `sylius_payment_method_notify` route-ot (`/payment-methods/{code}`),
ami szintén tokenmentes és statikus. Nem azt használjuk, mert:

- az egész `PaymentRequest` alrendszer `@experimental`,
- a `NotifyResponseProvider` `final` és nem gateway-enként bővíthető — fixen 204 üres választ
  ad, ami a SimplePay-nek használhatatlan (aláírt törzs kell), és csak globális dekorálással
  lenne felülírható,
- a `NotifyCommandProvider::supports()` `ACTION_STATUS`-t vizsgál `ACTION_NOTIFY` helyett,
- a bolt checkoutja a klasszikus Payum úton (`PayumPayResponseProvider`) megy.

Ha a Sylius később stabilizálja ezt az alrendszert, az átállás egy külön, önálló döntés.

## 7. Visszatérési végpont

```
GET /payment/simplepay/return?payum_token=…&r=…&s=…&e=…
```

1. Token ellenőrzés `HttpRequestVerifier`-rel, **invalidálás nélkül** — a Sylius after-pay
   oldalnak még kell a token.
2. `Client::parseReturn($r, $s)` — az eredmény **naplózódik és keresztellenőrződik** a
   fizetés `orderRef`/`transactionId` értékeivel. Eltérés → `error` napló, de a vevő oldala
   nem törik el. **Sosem dönt állapotot.** Hiányzó vagy érvénytelen `r`/`s` szintén csak napló.
3. `$gateway->execute(new Sync($payment))` — valódi `query()`. **Ez dönti el az állapotot.**
4. `$gateway->execute(new GetHumanStatus($payment))`, persist, flush.
5. Átirányítás a token `afterUrl`-jére → a Sylius szokásos köszönő/hibaoldalára.

> „A visszatérési adat tájékoztató, nem bizonyíték." Az `r` aláírt, tehát nem hamisítható,
> de csak azt mondja meg, mit lát a vásárló — nem azt, hogy a pénz megérkezett-e.

**Saját visszatérési sablon nincs.** A régi `simplepay_return.html.twig` egy párhuzamos
visszajelző felület volt a Sylius sajátja mellett; ez a spec megszünteti, és a standard
Sylius folyamatra bíz mindent.

## 8. Jóváírás konzol parancs

```
bin/console simplepay:refund <rendelésszám> [--amount=] [--record]
```

1. A rendelés befejezett SimplePay fizetésének megkeresése.
2. `--amount` nélkül a **parancs** számolja ki a teljes fizetett összeget; megadva a
   `SyliusAmountConverter`-en vezeti át.
3. A `simplepay_refund.amount` írása a details-be — **mindig kiírt, konkrét érték**.
   A `RefundAction` maga sosem alapértelmez „teljes összeg"-re; a döntés itt, a parancsban
   születik, ahol látható és naplózható.
4. Payum `Refund` végrehajtása → `RefundAction` → `Client::refund()`.
5. Persist, és a válasz mezőinek kiírása.

### 8.1 `RecordingHttpClient` — a mérés műszere

PSR-18 dekorátor, ami a **nyers kérés- és válasz-törzseket** fájlba menti
(`var/simplepay/{időbélyeg}-{endpoint}.{req,res}.json`). A `--record` kapcsoló aktiválja.

Ez az egyetlen eszköz zárja le a protokoll-csomag mindkét nyitott kérdését:

- egy **sikeres jóváírás** válaszalakja (ma dokumentációból származó mezőkészlet),
- a **`detailed: true`** lekérdezés extra mezői.

A rögzített nyers JSON a protokoll-csomagba kerül fixture-ként, az érzékeny mezők
(`customer`, `customerEmail`, `invoice`, `salt`) `"[REDACTED]"`-re cserélve, de a kulcsokat
megtartva — **külön PR-ben**, ahogy a rétegzés szabálya előírja.

> **Elhelyezési döntés.** A dekorátor PSR-18-only, semmit nem tud Syliusról, tehát elvileg a
> Payum csomagba is illene. Mégis itt van, mert a parancs is itt van, és mert a Payum csomag
> publikus felületét nem bővítjük egy diagnosztikai eszközzel. Ha később más környezetből is
> kell, akkor költözik — akkor lesz rá bizonyíték, hogy általános.

## 9. Admin rendelés-oldal

A plugin hozza a saját hook-template-jét és egy `SimplePayPaymentView` read-model
szolgáltatást, ami a `simplepay_state`-ből olvas: `transactionId`, státusz, utolsó IPN
időpontja, környezet, és az `ipnLog` utolsó bejegyzései.

Ezzel az alkalmazás `Payment` entitásából eltűnhet az öt SimplePay-metódus: az entitásnak
nem dolga a gateway details-ének értelmezése.

## 10. Tesztstratégia

| Osztály | Mit állít |
|---|---|
| `SyliusAmountConverter` | mindhárom pénznem, nulla, negatív, **oszthatatlan → kivétel** |
| `OrderReferenceFactory` / `Parser` | oda-vissza leképezés, kötőjeles rendelésszám, hibás alak |
| `ConvertPaymentAction` | teljes payload, hiányzó vevő/cím/locale → hangos hiba |
| pénznem-őr | eltérő pénznem → kivétel |
| `LocaleToLanguageMap` | a három ismert locale, ismeretlen → kivétel |

**A két controllerre nem építünk teszt-kernelt a pluginban.** Az end-to-end lefedés az
alkalmazás meglévő Behat/PHPUnit infrastruktúrájában készül, a takarítási szakaszban.
Egy külön Sylius teszt-kernel karbantartása többe kerülne, mint amennyit ér.

`phpstan level 9` és `ecs` tisztán.

## 11. Az alkalmazás takarítása

**A legutolsó lépés, csak működő, bekötött plugin után.**

| Mi | Sors |
|---|---|
| `src/Components/Payum/SimplePay/` (18 fájl, ~2270 sor) | törlés |
| `src/Controller/Shop/SimplePayReturnController.php` | törlés |
| `templates/shop/payment/simplepay_return.html.twig` | törlés |
| `templates/admin/payment_method/form/sections/gateway_configuration/simplepay.html.twig` | a pluginba költözik |
| `templates/bundles/SyliusAdminBundle/OrderShow/_payment.html.twig` | **teljes törlés** — csak a SimplePay blokkért létezett |
| `config/packages/_sylius.yaml` ~561–567. sor | a két hook-bejegyzés törlése |
| `src/Entity/Payment/Payment.php` öt SimplePay-metódusa | törlés; az osztály üres leszármazottá válik |
| `tests/Unit/Components/Payum/SimplePay/` | teljes törlés |
| `translations/messages.hu.yaml` `simplepay:` blokk | a pluginba költözik |
| `.env.local` `SIMPLEPAY_MERCHANT_ID`, `SIMPLEPAY_SECRET_KEY` | törlés — **semmi nem olvassa** a `src/`-ben és a `config/`-ban |

Hozzáadás: két `path` repository, a plugin `require`-ben, két bundle a `bundles.php`-ba,
a plugin routing importja, és `symfony/http-client` + `nyholm/psr7` átmozgatása
`require-dev`-ből `require`-be.

**Adatmigráció nincs.** Élesben nincs forgalom, a bolt fejlesztés alatt áll, a konfigurációs
kulcsok és a `payment.details` alakja szabadon törhető.

## 12. A mérés — külön, megjelölt záró lépés

Semmit nem implementálunk belőle előre.

1. Publikus elérhetőség biztosítása az IPN-hez (alagút vagy vhost — üzemeltetési döntés).
2. Az IPN-URL beállítása a SimplePay vezérlőpanelen, „Technikai adatok" alatt.
3. Egy valódi fizetés. Az `ipnLog.repeatCount` megmutatja: **egyszer** jött az IPN
   (elfogadták a visszaigazolásunkat), vagy **ismételve** (a `receiveDate` formátuma gyanús).
4. `simplepay:refund --record` → nyers `/refund` válasz → fixture a protokoll-csomagba,
   külön PR.
5. Ugyanaz a felvétel a `query` válaszra → a `detailed: true` extra mezőinek dokumentálása,
   szintén külön PR.

Ha a 3. pont ismétlést mutat, a javítás egy konstans a protokoll-csomagban — és akkor
**mérésünk lesz, nem következtetésünk**. Ha nem mutat, az 1. fázis döntése igazolódott,
és ezt bizonyítékkal tudjuk leírni.

## 13. Elfogadási kritériumok

1. Egy Sylius checkout végigmegy: `Payment` → `simplepay_request` → `/start` → átirányítás.
2. A visszatérő vevő a Sylius standard köszönő/hibaoldalát látja, az állapot `query()`-ből.
3. Egy aláírt IPN a `/payment/simplepay/ipn/{code}` végponton 200-as, aláírt visszaigazolást kap.
4. Az admin űrlap kiírja a bemásolandó IPN-URL-t egy mentett payment methodon.
5. `simplepay:refund --record` valódi jóváírást indít és rögzíti a nyers választ.
6. `phpstan level 9` és `ecs` tisztán mindkét csomagban.
7. A 11. fejezet minden sora elvégezve, és az alkalmazás tesztjei zöldek.

## 14. Hatókörön kívül

Kétlépéses fizetés, ismétlődő fizetés, tárolt kártya, `WIRE` fizetési mód, admin jóváírás
felület, `refunds: true` lekérdezés, a Sylius `PaymentRequest` alrendszerre való átállás,
és a protokoll-csomag bármilyen módosítása.
