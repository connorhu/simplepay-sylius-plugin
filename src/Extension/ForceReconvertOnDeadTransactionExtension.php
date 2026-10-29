<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Extension;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Exception\PaymentNotRestartableException;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\Generic;
use Payum\Core\Request\GetStatusInterface;
use Payum\Core\Request\Sync;
use Sylius\Bundle\PayumBundle\Action\CapturePaymentAction;
use Sylius\Component\Payment\Model\PaymentInterface;

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
 * ELAVULT FÜGGŐ ÁLLAPOT: egy lejárt, de NEM végleges helyi állapot
 * (INIT/INPAYMENT/AUTHORIZED/INFRAUD) elavult lehet — a vevő közben
 * fizethetett, és az IPN meg a visszatérés is elveszhetett. Ezért
 * újraindítás előtt `Sync` (`/query`) fut, és a FRISS állapot dönt:
 *
 *   - lezárult → nincs `NEW`, a frozen `CaptureAction` a lezárt ágon
 *     `PaymentAlreadySettledException`-t dob (nincs második terhelés);
 *   - halott végleges vagy INIT (a vevő el sem kezdett fizetni) → újraindulhat;
 *   - még folyamatban (INPAYMENT/AUTHORIZED/INFRAUD: az összeg lefoglalva
 *     lehet, a SimplePay még lezárhatja) → `PaymentNotRestartableException`;
 *   - a SimplePay nem ismeri (`TransactionNotFoundException`) → nem
 *     terhelhetett, újraindulhat;
 *   - bármely más lekérdezési hiba → `PaymentNotRestartableException`:
 *     bizonytalan állapotra nem indul új `/start`.
 *
 * A `PaymentNotRestartableException`-t a `PaymentAlreadySettledListener`
 * a Sylius szokásos oldalára irányítja. A végleges halott állapotokat
 * (CANCELLED/TIMEOUT/NOTAUTHORIZED/FRAUD) nem kérdezzük le: azok a
 * SimplePaynél sem válhatnak lezárttá.
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
        // A Payum kivétel esetén is meghívja az `onPostExecute`-et (a
        // külső szinteken is) — ilyenkor nincs mit eldönteni, és egy
        // ismételt `Sync` második `/query`-t küldene.
        if (null !== $context->getException()) {
            return;
        }

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

        if (!$state->status->isFinal()) {
            // Elavult függő állapot: a SimplePay dönt (lásd a docblockot).
            try {
                $state = $this->syncedState($context, $request);
            } catch (TransactionNotFoundException) {
                $request->markNew();

                return;
            } catch (\LogicException $exception) {
                // Programozási hiba, nem SimplePay-állapot: ne rejtse el
                // egy „nem kérdezhető le” átirányítás.
                throw $exception;
            } catch (\Throwable $exception) {
                throw PaymentNotRestartableException::stateUnknown($exception);
            }

            $fresh = $state->status;

            if (null === $fresh) {
                throw new \LogicException('A Sync után a SimplePay állapotnak ismertnek kell lennie.');
            }

            if (self::isSettled($fresh)) {
                return;
            }

            if (TransactionStatus::Init !== $fresh && !$fresh->isFinal()) {
                throw PaymentNotRestartableException::inProgress($fresh);
            }
        }

        $request->markNew();
    }

    /**
     * A `Sync`-et a Sylius fizetésen (a `GetStatus` ELSŐ modelljén) futtatjuk:
     * a Sylius `ExecuteSameRequestWithPaymentDetailsAction` ekkor a friss
     * állapotot visszaírja a fizetés details-ébe, és a következő `Capture`
     * azt olvassa. A beágyazott `GetStatus` ArrayObject-je ehhez nem jó: a
     * hídja `finally` ága ELŐBB futott le, mint ez a kiterjesztés, tehát az
     * abba írt eredményt senki nem látná, és a frozen `CaptureAction` mégis új
     * `/start`-ot indítana. A `CapturePaymentAction` mindig a fizetéssel
     * hívja a `GetStatus`-t; ha mégsem, hangosan elbukunk.
     */
    private function syncedState(Context $context, GetStatusInterface $request): TransactionState
    {
        $payment = $request instanceof Generic ? $request->getFirstModel() : null;

        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('A GetStatus első modellje nem Sylius fizetés; a Sync eredménye nem írható vissza.');
        }

        $context->getGateway()->execute(new Sync($payment));

        return Details::fromModel(ArrayObject::ensureArrayObject($payment->getDetails()))->state();
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
