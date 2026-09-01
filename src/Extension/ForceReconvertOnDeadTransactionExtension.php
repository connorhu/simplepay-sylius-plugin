<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Extension;

use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Bundle\PayumBundle\Action\CapturePaymentAction;

/**
 * A `Convert` csak akkor fut újra a Sylius `CapturePaymentAction`-jében, ha
 * a `GetStatus` `NEW`-t jelent
 * (`vendor/sylius/sylius/.../PayumBundle/Action/CapturePaymentAction.php:46-49`).
 * Egy HALOTT tranzakcióra (CANCELLED, TIMEOUT, NOTAUTHORIZED, FRAUD) ez
 * sosem igaz — az `attempt` számláló nem nő, és egy újraindított
 * tranzakció a RÉGI, lejárt `simplepay_request`-ből indít `/start`-ot,
 * UGYANAZZAL az `orderRef`-fel. Ez pont az az összekeveredés, amit az
 * `OrderReference` séma megakadályozni hivatott (`QueryResponse::byOrderRef()`
 * két tranzakciót olvasztana össze).
 *
 * A Sylius `CapturePaymentAction` VENDOR kód, amit ez a plugin nem
 * módosíthat. A `GetStatus`-t viszont a plugin SAJÁT gateway-én
 * (`simplepay` factory) keresztül futtatja — ezt a gateway-hez tartozó
 * Payum KITERJESZTÉSEK (extensions) látják, méghozzá minden hívásnál,
 * függetlenül attól, ki indította (`Payum\Core\Gateway::execute()`: az
 * `onPostExecute` minden `execute()`-hívás után lefut, a beágyazottakra
 * is).
 *
 * A TRÜKK: ha a `GetStatus` a NÉGY halott státusz egyikét jelenti
 * (`isCanceled()` = CANCELLED/TIMEOUT, `isFailed()` = NOTAUTHORIZED/FRAUD —
 * lásd `CodeConjure\SimplePayPayum\StatusMap::apply()`, ami e két jelzőre
 * KIMERÍTŐEN, más állapotot nem képezve, csak ezt a négyet képezi), ÉS ezt
 * a `GetStatus`-t KÖZVETLENÜL a Sylius `CapturePaymentAction` indította,
 * akkor újra `NEW`-nak jelöljük. A Sylius kód ezután `isNew()`-t lát, és
 * lefuttatja a `Convert`-et — épp úgy, mintha friss fizetés volna.
 *
 * MIÉRT BIZTONSÁGOS ez a manipuláció:
 *
 *   - A `FINISHED`/`REFUND`/`REVERSED` állapotok `markCaptured()`-re
 *     illetve `markRefunded()`-re képződnek — ezeket ez az osztály sosem
 *     érinti, tehát a `PaymentAlreadySettledException` védelme (a
 *     `simplepay-payum` `CaptureAction`-jében) érintetlen marad: a
 *     duplikált terhelés elleni védelem NEM ezen az osztályon múlik,
 *     hanem a frozen csomagén — ez az osztály csak azt a KÜLÖN hibát
 *     javítja, hogy egy halott tranzakcióra sosem futott újra a `Convert`.
 *   - Az "élő" állapotok (INIT, INPAYMENT, INFRAUD, AUTHORIZED)
 *     `markPending()`-re illetve `markAuthorized()`-ra képződnek — ezekre
 *     sem fut le az újramintázás, a vevő a még élő fizetőoldalra kerül
 *     vissza (`CaptureAction::isLive()` a `simplepay-payum` csomagban).
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

        if ($request->isCanceled() || $request->isFailed()) {
            $request->markNew();
        }
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
}
