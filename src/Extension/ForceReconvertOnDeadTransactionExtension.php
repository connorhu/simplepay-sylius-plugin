<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Extension;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Details;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Bundle\PayumBundle\Action\CapturePaymentAction;

/**
 * A `Convert` csak akkor fut újra a Sylius `CapturePaymentAction`-jében, ha
 * a `GetStatus` `NEW`-t jelent
 * (`vendor/sylius/sylius/.../PayumBundle/Action/CapturePaymentAction.php:46-49`).
 * Enélkül az `attempt` számláló nem nő, és egy újraindított tranzakció a
 * RÉGI `simplepay_request`-ből indít `/start`-ot, UGYANAZZAL az
 * `orderRef`-fel. Ez pont az az összekeveredés, amit az `OrderReference`
 * séma megakadályozni hivatott (`QueryResponse::byOrderRef()` két
 * tranzakciót olvasztana össze).
 *
 * A Sylius `CapturePaymentAction` VENDOR kód, amit ez a plugin nem
 * módosíthat. A `GetStatus`-t viszont a plugin SAJÁT gateway-én
 * (`simplepay` factory) keresztül futtatja — ezt a gateway-hez tartozó
 * Payum KITERJESZTÉSEK (extensions) látják, méghozzá minden hívásnál,
 * függetlenül attól, ki indította (`Payum\Core\Gateway::execute()`: az
 * `onPostExecute` minden `execute()`-hívás után lefut, a beágyazottakra
 * is).
 *
 * R32 — A DÖNTÉS a frozen `CaptureAction::execute()` (`vendor/codeconjure/
 * simplepay-payum/src/Action/CaptureAction.php:60-85`) HÁROM ágát tükrözi,
 * NEM egy státusz-lista másolatát:
 *
 *   1. lezárt (`isSettled()`: FINISHED/REFUND/REVERSED) → `PaymentAlready-
 *      SettledException`, nincs kérés;
 *   2. `$state->isLive($now)` → `HttpRedirect` a meglévő fizetőoldalra,
 *      nincs új tranzakció;
 *   3. egyébként → valódi új `/start`.
 *
 * A 3. ág az EGYETLEN eset, ahol friss `orderRef` kell — ez az osztály
 * PONTOSAN akkor jelöli a `GetStatus`-t `NEW`-nak, amikor a 3. ágat
 * választaná a frozen kód: NEM lezárt ÉS NEM élő. Ez korábban (R26) csak
 * négy állapotot (CANCELLED, TIMEOUT, NOTAUTHORIZED, FRAUD) fedett — ez a
 * lista KIHAGYTA a "függőben, de lejárt" esetet (INIT/INPAYMENT/AUTHORIZED/
 * INFRAUD, `isLive()` `false`, mert a fizetőoldal ablaka lejárt): egy
 * hétköznapi elhagyott checkoutnál a vevő később visszatér, és a `Convert`
 * ekkor SEM futott újra — a frozen `CaptureAction` a RÉGI `simplepay_request`-
 * ből indított volna friss `/start`-ot, UGYANAZZAL az `orderRef`-fel.
 *
 * A `TransactionState::isLive()`-t KÖZVETLENÜL a frozen csomagból hívjuk
 * (`vendor/codeconjure/simplepay-payum/src/Model/TransactionState.php:104`) —
 * ez publikus, nem másolat. Az `isSettled()`-nek NINCS publikus
 * megfelelője a frozen csomagban (a `CaptureAction`-beli privát), ezért ezt
 * az osztály alján egy TÜKÖR-metódus adja, kimerítő `match`-csal — ugyanaz
 * a házirend, mint az eredetiben: egy új `TransactionStatus` eset itt
 * fordítási hibát ad, nem néma `false`-t.
 *
 * A HARD LIMIT VÁLTOZATLAN: egy lezárt fizetésre ez az osztály SOSEM
 * jelöli `NEW`-nak a `GetStatus`-t.
 *
 * MIÉRT NEM SZIVÁROG KI a hatás máshova: a `Sylius\Bundle\PayumBundle\
 * Extension\UpdatePaymentStateExtension` ugyanezt a mintát használja saját
 * hatókör-szűkítésre — a `getPrevious()` verem-mélységét nézi, és csak a
 * VERSZINTŰ (nem beágyazott) `GetStatus`/`Notify` hívásokra reagál. Ez az
 * osztály a FORDÍTOTTJÁT teszi: csak a `CapturePaymentAction` által
 * KÖZVETLENÜL indított, beágyazott `GetStatus`-ra reagál — egy admin
 * listaoldal vagy más, közvetlenül indított `GetStatus`-lekérdezés
 * `getPrevious()`-ában nem a `CapturePaymentAction` az utolsó (legközelebbi
 * szülő) elem, tehát azokat ez az osztály változatlanul hagyja.
 */
final class ForceReconvertOnDeadTransactionExtension implements ExtensionInterface
{
    public function onPreExecute(Context $context): void
    {
    }

    public function onExecute(Context $context): void
    {
    }

    public function onPostExecute(Context $context): void
    {
        $request = $context->getRequest();

        if (!$request instanceof GetStatusInterface) {
            return;
        }

        if (!$this->wasCalledFromCapturePaymentAction($context)) {
            return;
        }

        // A `Sylius\Bundle\PayumBundle\Action\ExecuteSameRequestWithPayment-
        // DetailsAction` (a Sylius saját Payment→ArrayObject hídja a
        // `GetStatus`-hoz) a modellt ArrayObject-re cseréli, és SOSEM
        // állítja vissza a Payment entitásra — tehát itt, a beágyazott
        // hívás UTÁN, `$request->getModel()` már az ArrayObject-et adja,
        // ugyanazt a nyers `details`-t, amit a frozen `StatusAction` és
        // `CaptureAction` is olvas.
        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            return;
        }

        $state = Details::fromModel($model)->state();

        if (null === $state->status) {
            // Nincs tárolt állapot — friss fizetés. A `StatusMap` ilyenkor
            // már `markNew()`-t hívott, nincs mit tennünk.
            return;
        }

        if (self::isSettled($state->status)) {
            // A hard limit: sosem mintázunk újra egy lezárt fizetést.
            return;
        }

        if ($state->isLive(new \DateTimeImmutable())) {
            // Van még élő fizetőoldal — a frozen `CaptureAction` erre
            // irányít vissza, nem indít új tranzakciót.
            return;
        }

        $request->markNew();
    }

    /**
     * A KÖZVETLEN szülő kontextus akciója a döntő — nem a teljes verem —,
     * mert a `CapturePaymentAction` maga is állhat mélyebb hívásláncban
     * anélkül, hogy ez a tény a jelen döntést befolyásolná.
     */
    private function wasCalledFromCapturePaymentAction(Context $context): bool
    {
        $previous = $context->getPrevious();

        if ([] === $previous) {
            return false;
        }

        $parent = $previous[array_key_last($previous)];

        return $parent->getAction() instanceof CapturePaymentAction;
    }

    /**
     * A frozen `CaptureAction::isSettled()` TÜKRE — az eredeti PRIVÁT,
     * ezért innen nem hívható közvetlenül
     * (`vendor/codeconjure/simplepay-payum/src/Action/CaptureAction.php:128-143`).
     * A KÉT forrás szándékosan szó szerint egyezik, hogy egy jövőbeli
     * összevetés triviális legyen.
     */
    private static function isSettled(TransactionStatus $status): bool
    {
        return match ($status) {
            TransactionStatus::Finished,
            TransactionStatus::Refund,
            TransactionStatus::Reversed => true,
            TransactionStatus::Init,
            TransactionStatus::InPayment,
            TransactionStatus::Authorized,
            TransactionStatus::InFraud,
            TransactionStatus::Cancelled,
            TransactionStatus::Timeout,
            TransactionStatus::NotAuthorized,
            TransactionStatus::Fraud => false,
        };
    }
}
