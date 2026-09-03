<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Integration;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Action\CaptureAction as SimplePayCaptureAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\PaymentAlreadySettledException;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Extension\ForceReconvertOnDeadTransactionExtension;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Gateway;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
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
 * R26/R32 (2. találat) bizonyítéka: a VALÓDI
 * `Sylius\Bundle\PayumBundle\Action\CapturePaymentAction`-t hajtjuk végre —
 * ugyanúgy, ahogy az átvizsgáló tette —, egy „felvevő" (recording) Payum
 * `Gateway`-jel, ami minden `execute()`-hívás típusát naplózza.
 *
 * A frozen `codeconjure/simplepay-payum` csomagból ITT MÁR A VALÓDI
 * `CaptureAction`-t is használjuk (nem egy néma dublyát) — R32 kifejezetten
 * azt kéri, hogy lássuk, MEGY-e ki `/start`, és melyik `orderRef`-fel. A
 * VALÓDI hálózati hívást egy `Http\Mock\Client` (PSR-18) helyettesíti: ez
 * a `CodeConjure\SimplePay\Client`-en (frozen, de csak HASZNÁLT, nem
 * MÓDOSÍTOTT) keresztül fut, tehát a `CaptureAction` teljesen valós
 * kódúton megy végig — csak a ténylegesen kimenő HTTP-hívást fogja el egy
 * dublya, SOHA nem éri el a hálózatot. A dublya válaszát a helyes
 * SHA-384 aláírással látjuk el (`CodeConjure\SimplePay\Signature`
 * algoritmusa szerint), hogy a `Client::start()` saját aláírás-
 * ellenőrzése is átmenjen — enélkül a `CaptureAction` egy
 * `SignatureException`-t dobna, nem azt, amit mérni akarunk.
 *
 * A teljes státusz-mátrixot fedjük le, minden állapotot LEJÁRT és MÉG ÉLŐ
 * időzítéssel is:
 *
 *   - friss fizetés (nincs simplepay_state)                           → Convert fut, /start megy, friss orderRef
 *   - függőben (INIT/INPAYMENT/AUTHORIZED/INFRAUD), LEJÁRT időzítéssel → Convert fut, /start megy, friss orderRef (R32 ÚJ esete)
 *   - függőben, MÉG ÉLŐ időzítéssel                                    → Convert NEM fut, nincs /start (isLive() true)
 *   - halott végleges (CANCELLED/TIMEOUT/NOTAUTHORIZED/FRAUD), bármely időzítéssel → Convert fut, /start megy, friss orderRef
 *   - lezárt (FINISHED/REFUND/REVERSED), bármely időzítéssel          → Convert NEM fut, PaymentAlreadySettledException, nincs /start
 *
 * A "halott végleges" és "lezárt" csoportokat MINDKÉT időzítés-alakkal
 * teszteljük, hogy bizonyítsuk: a `TransactionState::isLive()` az időzítéstől
 * FÜGGETLENÜL `false`-t ad végleges (`isFinal()`) státuszra — ez az a hely,
 * ahol R32 explicit figyelmeztetése szerint a javítás elromolhatott volna.
 */
final class ReconvertOnDeadTransactionTest extends TestCase
{
    private const string PRIOR_ORDER_REF = 'EZ-2026-0042-17-1';

    private const string SECRET_KEY = 'titok-teszt-secret';

    /**
     * @return iterable<string, array{0: ?TransactionStatus, 1: string, 2: bool, 3: bool, 4: bool}>
     *
     * A tömb sorrendje: [státusz vagy null, időzítés ('expired'|'live'|'n/a'),
     * Convert fusson-e, /start menjen-e ki, PaymentAlreadySettledException legyen-e]
     */
    public static function matrix(): iterable
    {
        yield 'friss fizetés (nincs simplepay_state)' => [null, 'n/a', true, true, false];

        $pending = [
            TransactionStatus::Init,
            TransactionStatus::InPayment,
            TransactionStatus::Authorized,
            TransactionStatus::InFraud,
        ];

        foreach ($pending as $status) {
            yield sprintf('%s, LEJÁRT fizetőoldal (R32 új esete)', $status->value) => [$status, 'expired', true, true, false];
            yield sprintf('%s, MÉG ÉLŐ fizetőoldal', $status->value) => [$status, 'live', false, false, false];
        }

        $deadFinal = [
            TransactionStatus::Cancelled,
            TransactionStatus::Timeout,
            TransactionStatus::NotAuthorized,
            TransactionStatus::Fraud,
        ];

        foreach ($deadFinal as $status) {
            yield sprintf('%s (halott, végleges), lejárt időzítéssel', $status->value) => [$status, 'expired', true, true, false];
            yield sprintf('%s (halott, végleges), "élőnek tűnő" időzítéssel is', $status->value) => [$status, 'live', true, true, false];
        }

        $settled = [
            TransactionStatus::Finished,
            TransactionStatus::Refund,
            TransactionStatus::Reversed,
        ];

        foreach ($settled as $status) {
            yield sprintf('%s (lezárt), lejárt időzítéssel', $status->value) => [$status, 'expired', false, false, true];
            yield sprintf('%s (lezárt), "élőnek tűnő" időzítéssel is', $status->value) => [$status, 'live', false, false, true];
        }
    }

    #[DataProvider('matrix')]
    public function testTheFullStatusMatrixAgainstTheRealCapturePaymentAction(
        ?TransactionStatus $status,
        string $timeoutMode,
        bool $expectConvert,
        bool $expectStart,
        bool $expectSettledException,
    ): void {
        $result = $this->driveCapture($status, $timeoutMode);

        self::assertSame($expectConvert, $result->convertRan, 'A Convert futása nem egyezik a várttal.');
        self::assertSame($expectStart, $result->startRequestSent, 'A /start hívás megtörténte nem egyezik a várttal.');
        self::assertSame(
            $expectSettledException,
            $result->settledExceptionThrown,
            'A PaymentAlreadySettledException dobása nem egyezik a várttal.',
        );

        if ($expectStart) {
            self::assertNotNull($result->orderRefSent, 'A kimenő /start-nak orderRef-et kell vinnie.');

            if (null !== $status) {
                self::assertNotSame(
                    self::PRIOR_ORDER_REF,
                    $result->orderRefSent,
                    'A friss /start-nak ÚJ orderRef-fel kell mennie — a régi felhasználása pont az az összekeveredés, amit az OrderReference séma megakadályozni hivatott.',
                );
            }
        } else {
            self::assertNull($result->orderRefSent, 'Ha nem indul /start, nem lehet kimenő orderRef sem.');
        }

        // A settled ág és az élő-fizetőoldal ág is `HttpRedirect`-et vagy
        // `PaymentAlreadySettledException`-t dob — az egyetlen eset, ahol
        // EGYIK sem történik, az soha nem áll fenn ebben a mátrixban
        // (minden sor vagy /start-tal záruló HttpRedirect-et, vagy
        // meglévő fizetőoldalra visszairányító HttpRedirect-et, vagy
        // PaymentAlreadySettledException-t dob) — ezt implicit bizonyítja,
        // hogy minden ág lefutott a `gateway->execute()` catch nélkül nem
        // maradt volna nyitva.
    }

    private function driveCapture(?TransactionStatus $status, string $timeoutMode): CaptureResult
    {
        $now = new \DateTimeImmutable();

        $stateArray = [];
        $requestArray = [];

        if (null !== $status) {
            $stateArray = [
                'status' => $status->value,
                'attempt' => 1,
                'transactionId' => 'T-PRIOR',
            ];

            if ('n/a' !== $timeoutMode) {
                $stateArray['paymentUrl'] = 'https://sandbox.simplepay.hu/pay/prior';
                $stateArray['timeout'] = (
                    'expired' === $timeoutMode
                    ? $now->modify('-1 hour')
                    : $now->modify('+1 hour')
                )->format(\DateTimeInterface::ATOM);
            }

            $requestArray = ['orderRef' => self::PRIOR_ORDER_REF, 'attempt' => 1];
        }

        $payment = $this->payment($stateArray, $requestArray);

        $gateway = new RecordingGateway();

        $convertAction = new ConvertPaymentAction($this->urlGenerator());
        $convertAction->setGenericTokenFactory($this->genericTokenFactory());

        $descriptionProvider = $this->createStub(PaymentDescriptionProviderInterface::class);
        $descriptionProvider->method('getPaymentDescription')->willReturn('teszt');

        $mockHttpClient = new MockHttpClient();
        $mockHttpClient->addResponse($this->signedStartResponse());

        $psr17 = new Psr17Factory();
        $client = new Client(
            new Config('PUBLICTESTHUF', self::SECRET_KEY, Environment::Sandbox),
            $mockHttpClient,
            $psr17,
            $psr17,
        );
        $api = new Api($client, 'PUBLICTESTHUF', Environment::Sandbox, Currency::HUF);

        // A SORREND számít: a `Gateway::findActionSupported()` az első
        // TÁMOGATÓ akciót választja. A `CapturePaymentAction`-nek meg kell
        // előznie a frozen `SimplePayCaptureAction`-t, hogy a KÜLSŐ
        // (Payment modellű) Capture hozzá kerüljön, ne a frozenhez.
        $gateway->addAction(new CapturePaymentAction($descriptionProvider));
        $gateway->addAction(new ExecuteSameRequestWithPaymentDetailsAction());
        $gateway->addAction($convertAction);
        $gateway->addAction(new StatusAction());
        $gateway->addAction(new SimplePayCaptureAction());
        $gateway->addExtension(new ForceReconvertOnDeadTransactionExtension());
        $gateway->addApi($api);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getHash')->willReturn('capture-token-hash');
        $token->method('getGatewayName')->willReturn('simplepay');
        $token->method('getAfterUrl')->willReturn('https://bolt.hu/checkout/thank-you');

        // A VALÓDI Payum-folyamatban a `Capture` a tokennel konstruálódik
        // (`new Capture($token)`), majd egy tokenfeloldó akció `setModel()`-
        // lel cseréli a modellt a ténylegesre — a `Generic::setModel()` a
        // konstruktorban beállított tokent NEM érinti.
        $request = new Capture($token);
        $request->setModel($payment);

        $settledExceptionThrown = false;

        // EGYETLEN `\Throwable`-ágra van szükség — a PHPStan a Payum
        // dinamikus (reflection-alapú) akció-feloldását statikusan nem
        // tudja végigkövetni, tehát egy külön `catch (PaymentAlready-
        // SettledException)` ágat "sosem dobott" kivételnek látna, holott
        // a teszt ténylegesen bizonyítja, hogy dobódik (lásd a mátrix
        // "lezárt" sorait). A megkülönböztetés futásidejű `instanceof`.
        try {
            $gateway->execute($request);
        } catch (\Throwable $exception) {
            if ($exception instanceof PaymentAlreadySettledException) {
                $settledExceptionThrown = true;
            } elseif (!$exception instanceof HttpRedirect) {
                // Vagy a meglévő élő fizetőoldalra irányít vissza (nincs
                // /start), vagy a frissen indított tranzakció
                // fizetőoldalára (volt /start) — a HttpRedirect a Payum
                // szokásos, VÁRT vezérlésátadása, nem hiba. Bármi más
                // kivétel valódi teszthiba.
                throw $exception;
            }
        }

        $requests = $mockHttpClient->getRequests();
        $startRequestSent = [] !== $requests;
        $orderRefSent = null;

        if ($startRequestSent) {
            $lastRequest = $mockHttpClient->getLastRequest();
            $body = json_decode((string) $lastRequest?->getBody(), true, flags: \JSON_THROW_ON_ERROR);
            $orderRefSent = is_array($body) && is_string($body['orderRef'] ?? null) ? $body['orderRef'] : null;
        }

        return new CaptureResult(
            convertRan: in_array(Convert::class, $gateway->executedRequestClasses, true),
            startRequestSent: $startRequestSent,
            orderRefSent: $orderRefSent,
            settledExceptionThrown: $settledExceptionThrown,
        );
    }

    /**
     * A frozen `Client::start()` a válasz `Signature` fejlécét a SAJÁT
     * `CodeConjure\SimplePay\Signature` algoritmusával (HMAC-SHA384, a
     * `secretKey` felett) ellenőrzi — enélkül `SignatureException`-t dobna,
     * mielőtt bármit mérhetnénk.
     */
    private function signedStartResponse(): ResponseInterface
    {
        $payload = [
            'salt' => bin2hex(random_bytes(16)),
            'merchant' => 'PUBLICTESTHUF',
            'orderRef' => 'IGNORED-A-VALASZ-SAJAT-ORDERREFJE',
            'currency' => 'HUF',
            'total' => 100000,
            'timeout' => new \DateTimeImmutable('+30 minutes')->format(\DateTimeInterface::ATOM),
            'transactionId' => 'T-FAKE-START',
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/fake',
        ];

        $body = (string) json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $signature = base64_encode(hash_hmac('sha384', $body, trim(self::SECRET_KEY), true));

        return new Response(200, ['Signature' => $signature], $body);
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

/** A `driveCapture()` segédmetódus tipizált eredménye. */
final readonly class CaptureResult
{
    public function __construct(
        public bool $convertRan,
        public bool $startRequestSent,
        public ?string $orderRefSent,
        public bool $settledExceptionThrown,
    ) {
    }
}
