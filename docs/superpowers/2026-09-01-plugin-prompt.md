# A Sylius plugin végrehajtása — indító prompt

> Ez a dokumentum egy **új munkamenet indítására** készült, aminek nincs előzménye.
> Minden benne van, amire a folytatáshoz szükség van.
>
> Használat: nyiss új sessiont, és add oda ezt a fájlt kiindulásnak.
>
> Előzmény: a 2. fázis brainstormingja és tervezése 2026-08-31-én lezárult, a
> `codeconjure/simplepay-payum` csomag végrehajtása szintén.

---

## A feladat

Hajtsd végre a `codeconjure/simplepay-sylius-plugin` implementációs tervét, majd
takarítsd el a régi implementációt a boltból.

**Terv:** `/server/www/egyhazzene.hu/incubator/simplepay-sylius-plugin/docs/superpowers/plans/2026-08-31-simplepay-sylius-plugin.md` — **15 task**
**Spec:** `/server/www/egyhazzene.hu/incubator/simplepay-sylius-plugin/docs/superpowers/specs/2026-08-31-simplepay-sylius-plugin-design.md`

A terv minden taskban valódi, lefordítható kódot tartalmaz, nem leírást róla.

**Módszer:** `superpowers:subagent-driven-development`. Taskonként friss implementer,
utána review, ledger a `.superpowers/sdd/<terv-neve>/progress.md`-ben.

---

## Ami már kész

### 1. réteg — `codeconjure/simplepay` (protokoll)

| | |
|---|---|
| Hely | `/server/www/egyhazzene.hu/incubator/simplepay/` |
| Repo | https://github.com/connorhu/simplepay-lib — `main` |
| Állapot | **kész, pusholva, FAGYASZTVA** |

**Ehhez ne nyúlj hozzá.** 210 teszt, phpstan level 9, élő sandbox kontraktus-tesztek.
Nincs verzió-tagje és nincs a Packagiston — csak `dev-main`-ként oldható fel.

### 2. réteg — `codeconjure/simplepay-payum`

| | |
|---|---|
| Hely | `/server/www/egyhazzene.hu/incubator/simplepay-payum/` |
| Repo | https://github.com/connorhu/simplepay-payum — `main` @ `1359030` |
| Állapot | **kész, mergelve, pusholva, CI zöld** |

17/17 task, 179 teszt, phpstan level 9 + ECS tisztán. A CI **valódi GitHub
runneren** zöld PHP 8.4-en és 8.5-ön is — tehát a VCS-alapú függőség-feloldás
nem elmélet, hanem mért tény.

**A plugin `composer.json`-ja ugyanezt a mintát másolja.** A payum csomagé így néz ki:

```json
"require": {
    "codeconjure/simplepay": "dev-main",
    "codeconjure/simplepay-payum": "dev-main"
},
"repositories": [
    { "type": "vcs", "url": "https://github.com/connorhu/simplepay-lib" },
    { "type": "vcs", "url": "https://github.com/connorhu/simplepay-payum" }
],
"minimum-stability": "dev",
"prefer-stable": true
```

Egyik csomag sincs a Packagiston, és egyiknek sincs verzió-tagje — ezért `dev-main`.
Ha időközben tagelve lettek vagy felkerültek a Packagistra, a `repositories` blokk
elhagyható és a kikötés `^1.0` lehet; ezt **ellenőrizd**, ne feltételezd.

**Path repository a commitolt `composer.json`-ba nem kerülhet** — az csak egy gépen
működik, és a CI-t némán elrontja. Helyi fejlesztéshez a fájlon kívül adható meg:
```bash
composer config repositories.simplepay-payum path ../simplepay-payum
composer config --unset repositories.simplepay-payum   # commit előtt
```

---

## A Payum csomag publikus felülete, amit a plugin fogyaszt

Ezt **méréssel rögzítettük**, ne derítsd ki újra:

```php
// A két réteg közötti szerződés
CodeConjure\SimplePayPayum\Details
    public const string REQUEST_KEY = 'simplepay_request';
    public const string STATE_KEY   = 'simplepay_state';
    public const string REFUND_KEY  = 'simplepay_refund';
    public static function fromModel(\ArrayAccess $model): self
    public function startData(): StartData          // hiányzik → MissingDetailsException
    public function state(): TransactionState        // hiányzik → üres állapot
    public function writeState(TransactionState $state): void
    public function writeStartData(StartData $data): void
    public function refundAmount(): int              // hiányzik → MissingDetailsException
    public function clearRefund(): void              // a RefundAction hívja siker után

CodeConjure\SimplePayPayum\Model\StartData
    __construct(string $orderRef, int $total, Currency $currency, string $customerEmail,
                Invoice $invoice, Urls $urls, Language $language, array $methods, int $attempt)
    static fromArray(array): self    // a plugin Convertje ezt az alakot írja
    toArray(): array

CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn
    __construct(string $rawBody, string $signature)
    getMessage(): IpnMessage         // csak a gateway végrehajtása után

CodeConjure\SimplePayPayum\Api
    public readonly Client $client, string $merchant, Environment $environment, Currency $currency
```

**Gateway config kulcsok:** `merchant`, `secretKey`, `environment` (`sandbox`|`production`),
`currency` (`HUF`|`EUR`|`USD`).

**Payum requestek:** `Capture`, `Notify`, `ResolveSimplePayIpn`, `Sync`, `GetStatus`, `Refund`.

**Egy új, caller-látható szerződés, amit a terv írásakor még nem ismertünk:**
a `Capture` `PaymentAlreadySettledException`-t dob, ha a tárolt státusz
`FINISHED`, `REFUND` vagy `REVERSED` — és nem küld kérést. Újrapróbálkozás csak
sikertelen kísérlet után jogos (`CANCELLED`, `TIMEOUT`, `NOTAUTHORIZED`, `FRAUD`).
**A plugin Convertjének és a checkout-folyamatnak számolnia kell ezzel.**

---

## Amit az 1. csomag végrehajtása tanított — vidd át

44 döntés született menet közben; ezek a hordozhatók. **Minden implementer-dispatch
vigye őket, ne review-ban derüljenek ki.**

### Kötelező kódminták

1. **Az actionök `execute()`-ja explicit `instanceof` őrökkel szűkítsen**, ne
   `assertSupports()` + `@var` párral:
   ```php
   if (!$request instanceof Convert) {
       throw RequestNotSupportedException::createActionNotSupported($this, $request);
   }
   ```
   Ok: a `@var` minta minden actionben ütközik az ECS
   `InlineDocCommentDeclarationSniff`-jével, és önértékadás-hegesztést csábít ki.
   Az őr valódi futásidejű ellenőrzés ÉS olyan szűkítés, amit a phpstan követni tud.
   Mérve: az őr törlésekor nyers `TypeError` szökik ki a Payum
   `RequestNotSupportedException`-je helyett — ami megtörné a Payum action-láncát.

2. **Minden őrhöz teszt jár**: rossz típusú request, és rossz típusú modell.

3. **`createStub()`, nem `createMock()`** minden olyan test double-höz, amin nem
   ellenőrzünk elvárást. A PHPUnit 12 különben `OK, but there were issues!`-szal
   zár, és egy suite, ami rendszeresen figyelmeztetéssel végződik, leszoktat a
   záró sor olvasásáról. **A `#[AllowMockObjectsWithoutExpectations]` tilos** —
   elnémítja az üzenetet anélkül, hogy javítaná, amire mutat.
   *A plugin terve bőven használ `createMock`-ot Sylius interfészekre — ezeket
   végig kell nézni.*

### Kötelező fegyelmi szabályok

- **Phpstan hibára a kód vagy a teszt javul, SOSEM a konfiguráció tágul.**
  Nincs `ignoreErrors`, nincs inline `@phpstan-ignore`, nincs `@var` a `src/`-ben.
  Ha az implementer szerint elkerülhetetlen, kérdezzen, ne némítson.
- **Minden komment és docblock magyarul.** Angol komment a kódbázisban hiba.
- **Commit üzenet nyelvtanilag helyes magyarul**, Conventional Commits előtaggal.
  Valódi mondat, nem szóról szóra összerakott.
- **Soha ne találj ki parancskimenetet, commit hasht vagy időbélyeget.**
- **Soha ne állítsd, hogy valami „bizonyított", ha nem futtattál olyan kísérletet,
  ami ellenkező esetben megbukott volna.**

### Modellválasztás — mérve

- **Implementerek: sonnet.** A haiku a *kódot* pontosan átírja, de a körülötte lévő
  konvenciókon rendre elcsúszik (angol kommentek, cirill commit üzenet,
  linter-elnémítás). Ez egyetlen taskon 5 dispatchbe és 2 review-ba került —
  többe, mint amit a modellár megspórolt.
- **Task-review-k: sonnet.** Ezek találták meg az összes valódi hibát.
- **Whole-branch review a végén: opus.** Három blokkoló hibát talált, amit
  15 task-szintű review átengedett.
- **Scoped re-review kis diffre: haiku** elég.

---

## Amire a review-kat rá kell állítani

Az 1. csomag 12 javítási köréből **gyakorlatilag mind a terv hibája volt**, nem az
implementereké. A hibák jellege eltolódott: az elején formaiak, később mind
ugyanaz a fajta — **a kód helyes, a teszt zöld, de a teszt nem védi azt, amit véd.**

Négy ilyen volt, és **mind csak mutációval látszott**:

- a `matches()` tesztje nem zárta ki a figyelmen kívül hagyandó mezőket
- az `isLive()` négy feltételéből kettő szabadon törölhető volt
- a tervben „a legfontosabb teszt"-ként jelölt eset nem különböztette meg a helyes
  implementációt a naivtól (a ciklus pont ott állt meg, ahol a kettő még egyezik)
- a `TransitionGuard` `Finished`-korlátozása szabadon törölhető volt

**Ezért minden task-review-dispatch kérjen kísérletet, ne olvasást:** rontsa el a
terhelést viselő viselkedést, és nézze meg, bukik-e teszt. Ha nem bukik, az
finding — akkor is, ha a kód helyes.

Ugyanez a dokumentációra: **háromszor** kaptunk rajta docblockot vagy README-t
azon, hogy méretlen tényt állított igazoltként. Egy esetben a Payum
`offsetGet()` viselkedéséről szóló állítás **hamis** volt, és a reviewer a vendor
forrásából cáfolta meg.

---

## A három blokkoló hiba, amit csak az egész látott

A 15 task-szintű review mind átment. A whole-branch review hármat talált, ami
**egyetlen fájlon belül nem látszik**. Ez a mintázat a plugin esetén is várható,
ezért a végén **kötelező** whole-branch review a legerősebb modellen:

1. **Dupla terhelés.** Az `isLive()` őr nem-végleges státuszt követelt, `FINISHED`
   viszont végleges — egy kifizetett rendelésre valódi `/start` ment volna.
   Két metódus találkozásánál élt.
2. **A bundle nem tudott bootolni `logger` szolgáltatás nélkül.** A `services.xml`
   `on-invalid="null"`-t ígért, a konstruktor nem-nullable-t követelt. A
   `src/Bundle/`-nek nulla tesztje volt — az egyetlen integrációs varrat, amit a
   csomag birtokolt, teszteletlen volt.
3. **Dupla jóváírás.** A `simplepay_refund.amount` sosem törlődött; a `details`
   szerződésben senki nem birtokolta a törlést.

**Tanulság a pluginra:** a DI-bekötés (`services.xml`), a routing és a Sylius
hook-nevek ugyanilyen varratok. A terv 13. taskja hoz egy
`ServiceDefinitionTest`-et — az kevés. Kérj konténer-fordítási tesztet is.

---

## Ismert korlát, amit a plugin örököl

**A részleges jóváírás Payum felé teljesként látszik.** A `StatusMap` a `REFUND`-ot
`markRefunded()`-re képezi a `remainingTotal` figyelmen kívül hagyásával, és a
`REFUND` státusz után az átmenet-őr minden későbbi `Sync` státuszváltását
visszautasítja. A `RefundAction` a `refundTotal`-t hívásonként felülírja, nem
halmozza.

Ez **tervezési következmény**, nem hiba — az őr szigorúsága szándékos, és a
`Refund → Finished` átmenet engedélyezése `remainingTotal > 0` esetén valódi
tervezési döntés. A `simplepay-payum` README-je „Ismert korlátok" alatt rögzíti.

**A plugin `simplepay:refund` parancsa részleges összeget is elfogad**, tehát ez a
korlát ott válik láthatóvá. Legalább a plugin README-je mondja ki. Ha a
megrendelő rendezni akarja, az külön döntés és külön PR.

---

## Halasztott tételek az 1. csomagból

Egyik sem blokkolt, mind a ledgerben van indoklással:

- `TransactionState::intList()` csendben ejti a nem-int elemeket (`lastErrorCodes`)
- `Api::$environment` a `src/`-ben olvasatlan
- a csomag nem kínál `parseReturn()` megfelelőt — a plugin visszatérési
  controllere emiatt **nem** ellenőrzi az `r`/`s`-t, csak naplózza; a spec 7.
  fejezete ezt indokolja
- öt action `execute()`-ja azonos 10 soros preambulummal nyit

---

## A terv három nyitott döntése

Szándékosan maradtak nyitva; a válasz csak kódírás közben derül ki:

1. **Task 4** — `LocaleToLanguageMap::resolve()` statikus vagy példány-metódus.
   Ajánlás: csak statikus, YAGNI.
2. **Task 8** — ismeretlen rendelésnél mi legyen a `Notify` modellje.
   Ajánlás: eldobható `\ArrayObject`, és a teszt `assertCount` értékének igazítása.
3. **Task 15, 3. lépés** — a rendelés-oldali admin hook pontos neve.
   A `bin/console debug:hooks` mondja meg.

---

## A bolt

**Sylius 2.1 + Symfony 7.2, PHP 8.4**, `/server/www/egyhazzene.hu/bolt`, `main` ág, tiszta.

**Mért tények, amiket ne deríts ki újra:**

- `payum/core` **1.7.7**, nem 2.0
- a bolt checkoutja a **klasszikus Payum utat** járja (`PayumPayResponseProvider`),
  nem a `PaymentRequest` alrendszert — az `@experimental`, és a
  `NotifyCommandProvider::supports()`-ában látható hiba van
- a Sylius `ExecuteSameRequestWithPaymentDetailsAction` **minden gateway-re** be van
  kötve (`all="true"`), és a details-t `finally` ágban írja vissza — tehát a
  `NotifyAction` `HttpResponse` reply-ja után is megmarad az állapotfrissítés
- a Payum Symfony-hídjai töltik a `$request->headers`-t, a PlainPhp híd **nem**
- `Payum\Core\Request\GetHttpRequest::offsetGet()` **nem** csomagol újra beágyazott
  tömböket — csak a `getArray()` teszi
- a bolt egyetlen locale-t használ: `hu_HU`
- a `.env.local` `SIMPLEPAY_*` változóit **semmi nem olvassa** — a takarítás része

**Az utolsó task (15.) az EGYETLEN, ami a bolt forrásához nyúl.** Csak akkor kezdd
el, ha a plugin minden korábbi taskja zölden fut.

---

## A mérés — a fázis igazi zárása

A takarítás után jön a kézi végigvitel, amiből a protokoll-csomag **két nyitott
kérdése** lezárható. Ez nem opcionális ráadás, ez a fázis célja:

1. Publikus elérhetőség az IPN-hez (alagút vagy vhost — **üzemeltetési döntés,
   kérdezd meg**).
2. Az IPN-URL beállítása a SimplePay vezérlőpanelen, „Technikai adatok" alatt.
3. Egy valódi fizetés. **Az `ipnLog.repeatCount` a műszer:** ha 1, elfogadták az
   aláírt visszaigazolásunkat; ha nő, nem — és a legvalószínűbb ok a `receiveDate`
   formátuma (`+02:00` vs `+0200`). Ez ma **következtetés, nem mérés**.
4. `bin/console simplepay:refund <rendelésszám> --record` → nyers `/refund` válasz
   → fixture a protokoll-csomagba, **külön PR-ben**.
5. Ugyanaz a felvétel a `query` válaszra → a `detailed: true` extra mezői.

**Fontos:** a megrendelőnek **éles SimplePay fiókja van, sandboxa nincs.** Ez azt
jelenti, hogy a mérés valódi pénzmozgással jár. Ez az ő döntése — kérdezd meg,
mielőtt bármit indítasz, és ne feltételezz jóváhagyást korábbi üzenetekből.

---

## Amit ne csinálj

- **Ne nyúlj a `codeconjure/simplepay` protokoll-csomaghoz.** Ha valóban általános
  képesség hiányzik, az külön döntés és külön PR.
- **Ne nyúlj a `codeconjure/simplepay-payum` csomaghoz** sem, hacsak a plugin
  fejlesztése közben ki nem derül, hogy valóban hiányzik belőle valami. Akkor is:
  külön commit, külön indoklás.
- **Ne írj a bolt forrásába**, amíg a plugin nem áll készen — az app takarítása a
  legutolsó lépés.
- **Ne másolj kódot a GPL-es hivatalos SDK-ból.** Tényeket igen, kódot nem.
- **Ne indíts éles fizetést vagy jóváírást a megrendelő kifejezett jóváhagyása
  nélkül.**
- **Ne hagyj path repositoryt a commitolt `composer.json`-ban** — az csak egy gépen
  működik, és a CI-t némán elrontja.
- **Ne vedd ki a `.github/workflows/ci.yaml`-t a commitból, ha a push elakad rajta.**
  Az csendben megváltoztatná, amit szállítunk. Lásd lent a megoldást.

---

## Egy buktató a pushnál, amit már megfizettünk

A payum csomag pusholása **elsőre elakadt**, nem a kódon:

```
refusing to allow an OAuth App to create or update workflow
`.github/workflows/ci.yaml` without `workflow` scope
```

A `gh` OAuth token hatókörei `repo`, `read:org`, `gist`, `admin:public_key` —
**`workflow` nincs köztük**, márpedig a Task 1 létrehozza a CI workflow fájlt.
A payum repo `https` remote-tal volt beállítva, tehát a push az OAuth tokenen ment.

**A plugin repo remote-ja már SSH** (`git@github.com:connorhu/simplepay-sylius-plugin.git`),
mert a `gh repo create --source=.` így állította be — tehát **ez a hiba itt
várhatóan nem jön elő**. Ha mégis (pl. valaki újraklónozza https-sel), a megoldás
a remote SSH-ra állítása:

```bash
git remote set-url origin git@github.com:connorhu/simplepay-sylius-plugin.git
```

Az SSH nem az OAuth tokenen hitelesít, tehát a korlát nem érvényes rá. A tartós
megoldás a megrendelő oldalán a `gh auth refresh -h github.com -s workflow`, de az
böngészős, tehát **nem a te dolgod** — ha odáig jutsz, szólj neki.

---

## Hol van a részletes anyag

- **Az 1. csomag teljes döntésnaplója (44 döntés indoklással):**
  `/server/www/egyhazzene.hu/incubator/simplepay-payum/.superpowers/sdd/2026-08-31-simplepay-payum/progress.md`
  Mellette 17 task-jelentés, az összes review-diff, a javítási hullám és a
  konszolidáció jelentése. Ha egy „miért így van ez?" kérdés felmerül, ott a válasz.
- **Az 1. fázis forensikus anyaga:**
  `/server/www/egyhazzene.hu/incubator/simplepay/.superpowers/sdd/2026-08-30-simplepay-protocol-package/`
- **A SimplePay dokumentáció:** `PaymentService_SimplePay_2.x_Payment_HU_260504.pdf`
  a https://simplepay.hu/fejlesztoknek/ oldalról. A hivatalos PHP SDK **GPL-3.0**,
  a mi csomagjaink MIT-esek.
