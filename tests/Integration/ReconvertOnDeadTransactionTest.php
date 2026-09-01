<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Integration;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Extension\ForceReconvertOnDeadTransactionExtension;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Gateway;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Action\CapturePaymentAction;
use Sylius\Bundle\PayumBundle\Action\ExecuteSameRequestWithPaymentDetailsAction;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * R26 (2. találat) bizonyítéka: a VALÓDI
 * `Sylius\Bundle\PayumBundle\Action\CapturePaymentAction`-t hajtjuk végre —
 * ugyanúgy, ahogy az átvizsgáló tette —, egy „felvevő" (recording) Payum
 * `Gateway`-jel, ami minden `execute()`-hívás típusát naplózza. A frozen
 * `codeconjure/simplepay-payum` csomagból KIZÁRÓLAG a `StatusAction`-t
 * használjuk (tiszta olvasás, nincs hálózat) — a `CaptureAction`-t
 * SZÁNDÉKOSAN NEM: az egy valódi `/start` hívást indítana a SimplePay felé,
 * amit ez a feladat kifejezetten tilt. A `Capture(ArrayAccess)`-t egy néma
 * felvevő dublya "kezeli" — pont úgy, ahogy az átvizsgáló saját "recording
 * gateway"-je is csak a KÉRÉS-SOROZATOT bizonyította, nem a tényleges
 * SimplePay-hívást.
 *
 * A négy forgatókönyv R26 pontos állítását fedi le:
 *   - friss fizetés (nincs simplepay_state)                → Convert fut
 *   - halott tranzakció (TIMEOUT — `isCanceled()` csoport)  → Convert ÚJRA fut
 *   - halott tranzakció (FRAUD — `isFailed()` csoport)      → Convert ÚJRA fut
 *   - élő tranzakció (INIT)                                 → Convert NEM fut
 *   - lezárt tranzakció (FINISHED)                           → Convert NEM fut
 *   - lezárt tranzakció (REFUND)                             → Convert NEM fut
 */
final class ReconvertOnDeadTransactionTest extends TestCase
{
    public function testConvertRunsForAFreshPayment(): void
    {
        $log = $this->capture($this->payment(stateArray: []));

        self::assertContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        self::assertSame(1, $log->attemptAfter);
    }

    public function testConvertRunsAgainAfterATimedOutTransactionDeadCanceledGroup(): void
    {
        $log = $this->capture($this->payment(stateArray: [
            'status' => TransactionStatus::Timeout->value,
            'attempt' => 1,
            'transactionId' => 'T1',
        ], requestArray: ['orderRef' => 'EZ-2026-0042-17-1']));

        self::assertContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        self::assertSame(2, $log->attemptAfter);
        self::assertNotSame('EZ-2026-0042-17-1', $log->orderRefAfter);
        self::assertSame('EZ-2026-0042-17-2', $log->orderRefAfter);
    }

    public function testConvertRunsAgainAfterAFraudRejectedTransactionDeadFailedGroup(): void
    {
        $log = $this->capture($this->payment(stateArray: [
            'status' => TransactionStatus::Fraud->value,
            'attempt' => 3,
            'transactionId' => 'T3',
        ], requestArray: ['orderRef' => 'EZ-2026-0042-17-3', 'attempt' => 3]));

        self::assertContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        self::assertSame(4, $log->attemptAfter);
        self::assertSame('EZ-2026-0042-17-4', $log->orderRefAfter);
    }

    public function testConvertDoesNotRunForALiveTransaction(): void
    {
        $log = $this->capture($this->payment(stateArray: [
            'status' => TransactionStatus::Init->value,
            'attempt' => 1,
            'transactionId' => 'T1',
        ], requestArray: ['orderRef' => 'EZ-2026-0042-17-1', 'attempt' => 1]));

        self::assertNotContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        // Az `attempt` és az `orderRef` VÁLTOZATLAN marad — a régi
        // `simplepay_request` sértetlen, a még élő fizetőoldal
        // újrafelhasználható (`CaptureAction::isLive()`, a frozen
        // csomagban — ezt a tesztet itt nem szimuláljuk, csak azt
        // bizonyítjuk, hogy a `Convert` nem futott újra, tehát a
        // `simplepay_request` nem íródott felül).
        self::assertSame(1, $log->attemptAfter);
        self::assertSame('EZ-2026-0042-17-1', $log->orderRefAfter);
    }

    public function testConvertDoesNotRunForASettledFinishedTransaction(): void
    {
        $log = $this->capture($this->payment(stateArray: [
            'status' => TransactionStatus::Finished->value,
            'attempt' => 1,
            'transactionId' => 'T1',
        ], requestArray: ['orderRef' => 'EZ-2026-0042-17-1', 'attempt' => 1]));

        self::assertNotContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        self::assertSame(1, $log->attemptAfter);
        self::assertSame('EZ-2026-0042-17-1', $log->orderRefAfter);
    }

    public function testConvertDoesNotRunForASettledRefundedTransaction(): void
    {
        $log = $this->capture($this->payment(stateArray: [
            'status' => TransactionStatus::Refund->value,
            'attempt' => 1,
            'transactionId' => 'T1',
        ], requestArray: ['orderRef' => 'EZ-2026-0042-17-1', 'attempt' => 1]));

        self::assertNotContains(\Payum\Core\Request\Convert::class, $log->executedRequestClasses);
        self::assertSame(1, $log->attemptAfter);
        self::assertSame('EZ-2026-0042-17-1', $log->orderRefAfter);
    }

    private function capture(PaymentInterface $payment): CaptureLog
    {
        $gateway = new RecordingGateway();

        $convertAction = new ConvertPaymentAction($this->urlGenerator());
        $convertAction->setGenericTokenFactory($this->genericTokenFactory());

        $descriptionProvider = $this->createStub(PaymentDescriptionProviderInterface::class);
        $descriptionProvider->method('getPaymentDescription')->willReturn('teszt');

        // A SORREND számít: a `Gateway::findActionSupported()` az első
        // TÁMOGATÓ akciót választja. A `CapturePaymentAction`-nek meg kell
        // előznie a néma felvevőt, hogy a KÜLSŐ (Payment modellű) Capture
        // hozzá kerüljön, ne a felvevőhöz.
        $gateway->addAction(new CapturePaymentAction($descriptionProvider));
        $gateway->addAction(new ExecuteSameRequestWithPaymentDetailsAction());
        $gateway->addAction($convertAction);
        $gateway->addAction(new StatusAction());
        $gateway->addAction(new SilentArrayAccessCaptureAction());
        $gateway->addExtension(new ForceReconvertOnDeadTransactionExtension());

        $token = $this->createStub(TokenInterface::class);
        $token->method('getHash')->willReturn('capture-token-hash');
        $token->method('getGatewayName')->willReturn('simplepay');
        $token->method('getAfterUrl')->willReturn('https://bolt.hu/checkout/thank-you');

        // A VALÓDI Payum-folyamatban a `Capture` a tokennel konstruálódik
        // (`new Capture($token)` — lásd `Sylius\...\Factory\CaptureFactory`),
        // majd egy tokenfeloldó akció `setModel()`-lel cseréli a modellt a
        // ténylegesre — a `Payum\Core\Request\Generic::setModel()` a
        // konstruktorban beállított tokent NEM érinti. Ez a két lépés adja
        // vissza pontosan azt, amit `CapturePaymentAction` a `$request->
        // getToken()`-nel vár: egy VALÓS tokent, a Sylius Payment modell
        // mellett.
        $request = new Capture($token);
        $request->setModel($payment);

        $gateway->execute($request);

        $details = $payment->getDetails();
        $requestArray = $this->typedArray($details[Details::REQUEST_KEY] ?? null);
        $attempt = $requestArray['attempt'] ?? null;
        $orderRef = $requestArray['orderRef'] ?? null;

        // Az `attempt`-et a `simplepay_request` (StartData) névtérből
        // olvassuk, NEM a `simplepay_state`-ből: a `Convert` (amit ez a
        // teszt vizsgál) a StartData-t írja, a `TransactionState.attempt`-et
        // a — itt szándékosan néma dublyával helyettesített — VALÓDI
        // `CaptureAction` frissítené egy sikeres `/start` után.
        return new CaptureLog(
            executedRequestClasses: $gateway->executedRequestClasses,
            attemptAfter: is_int($attempt) ? $attempt : 0,
            orderRefAfter: is_string($orderRef) ? $orderRef : '',
        );
    }

    /**
     * A `Payment::getDetails()` `mixed`-et ígér a namespace-kulcsokra — ez
     * az egyetlen hely, ahol a nyers értéket tömbre szűkítjük.
     *
     * @return array<string, mixed>
     */
    private function typedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $typed = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $typed[$key] = $item;
            }
        }

        return $typed;
    }

    private function genericTokenFactory(): GenericTokenFactoryInterface
    {
        $returnToken = $this->createStub(TokenInterface::class);
        $returnToken->method('getHash')->willReturn('return-token-hash');

        $factory = $this->createStub(GenericTokenFactoryInterface::class);
        $factory->method('createToken')->willReturn($returnToken);

        return $factory;
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
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

    /**
     * @param array<string, mixed> $stateArray
     * @param array<string, mixed> $requestArray
     */
    private function payment(array $stateArray, array $requestArray = []): PaymentInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getFullName')->willReturn('Teszt Elek');
        $address->method('getCountryCode')->willReturn('HU');
        $address->method('getCity')->willReturn('Budapest');
        $address->method('getPostcode')->willReturn('1011');
        $address->method('getStreet')->willReturn('Fő utca 1.');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('vevo@example.com');
        $customer->method('getId')->willReturn(1);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn('EZ-2026-0042');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getLocaleCode')->willReturn('hu_HU');
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getCurrencyCode')->willReturn('HUF');

        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn('simplepay');
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        $details = [];

        if ([] !== $requestArray) {
            $details[Details::REQUEST_KEY] = $requestArray;
        }

        if ([] !== $stateArray) {
            $details[Details::STATE_KEY] = $stateArray;
        }

        $detailsRef = $details;

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getAmount')->willReturn(100000);
        $payment->method('getCurrencyCode')->willReturn('HUF');
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn($method);
        // `CapturePaymentAction` `setDetails()`-t hív, majd később ÚJRA
        // `getDetails()`-t olvas — ehhez a dublyának ÁLLAPOTOSNAK kell
        // lennie, nem csak egyszer beállított, rögzített választ adnia.
        $payment->method('getDetails')->willReturnCallback(
            static function () use (&$detailsRef): array {
                return $detailsRef;
            },
        );
        $payment->method('setDetails')->willReturnCallback(
            /** @param array<string, mixed> $newDetails */
            static function (array $newDetails) use (&$detailsRef): void {
                $detailsRef = $newDetails;
            },
        );

        return $payment;
    }
}

/**
 * Payum `Gateway`, ami minden `execute()`-hívás REQUEST-osztályát naplózza
 * — a BEÁGYAZOTTAKAT is, mert az öröklött `findActionSupported()` az
 * override-olt `execute()`-öt hívó PÉLDÁNYT ($this) állítja be minden akció
 * gateway-jeként (`GatewayAwareInterface::setGateway()`), tehát minden
 * beágyazott `$this->gateway->execute(...)` hívás is EZEN a példányon
 * keresztül fut, nem a szülő Payum `Gateway`-en.
 */
final class RecordingGateway extends Gateway
{
    /** @var list<class-string> */
    public array $executedRequestClasses = [];

    /**
     * @param mixed $request
     * @param bool  $catchReply
     */
    public function execute($request, $catchReply = false)
    {
        if (is_object($request)) {
            $this->executedRequestClasses[] = get_class($request);
        }

        return parent::execute($request, $catchReply);
    }
}

/**
 * A frozen `codeconjure/simplepay-payum` `CaptureAction` helyettesítője
 * ebben a tesztben: NEM indít valódi `/start` hívást. A teszt tárgya az,
 * HOGY a `Convert` újrafut-e — nem az, hogy a mögöttes SimplePay-hívás mit
 * csinál (azt a frozen csomag saját, itt nem módosított tesztjei fedik).
 */
final class SilentArrayAccessCaptureAction implements ActionInterface
{
    public function execute($request): void
    {
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof \ArrayAccess;
    }
}

/** A `capture()` segédmetódus tipizált eredménye. */
final readonly class CaptureLog
{
    /** @param list<class-string> $executedRequestClasses */
    public function __construct(
        public array $executedRequestClasses,
        public int $attemptAfter,
        public string $orderRefAfter,
    ) {
    }
}
