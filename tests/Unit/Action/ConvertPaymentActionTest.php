<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Exception\MissingGenericTokenFactoryException;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryInterface;
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

    /**
     * A capture token `afterUrl`-je — a Sylius köszönő/hibaoldala, amire a
     * mintázott visszatérési tokennek is mutatnia kell (R25).
     */
    private const string CAPTURE_AFTER_URL = 'https://bolt.hu/checkout/thank-you';

    /** A `GenericTokenFactory` által mintázott, dedikált visszatérési token hash-e. */
    private const string RETURN_TOKEN_HASH = 'return-token-hash-456';

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

    /** @param array<string, mixed> $details */
    private function payment(
        ?int $amount = 100000,
        ?string $currency = 'HUF',
        ?string $email = 'vevo@example.com',
        ?string $locale = 'hu_HU',
        bool $withAddress = true,
        string $factoryName = 'simplepay',
        string $gatewayCurrency = 'HUF',
        array $details = [],
    ): PaymentInterface {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getFullName')->willReturn('Teszt Elek');
        $address->method('getCountryCode')->willReturn('HU');
        $address->method('getCity')->willReturn('Budapest');
        $address->method('getPostcode')->willReturn('1011');
        $address->method('getStreet')->willReturn('Fő utca 1.');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn('EZ-2026-0042');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getLocaleCode')->willReturn($locale);
        $order->method('getBillingAddress')->willReturn($withAddress ? $address : null);

        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => $gatewayCurrency,
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('simplepay');

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn($currency);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);

        return $payment;
    }

    private function token(): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getHash')->willReturn(self::TOKEN_HASH);
        $token->method('getGatewayName')->willReturn('simplepay');
        $token->method('getAfterUrl')->willReturn(self::CAPTURE_AFTER_URL);

        return $token;
    }

    /**
     * A `GenericTokenFactory` dublja: `createToken()`-je mindig a
     * `$returnHash`-sel rendelkező tokent adja vissza, függetlenül a kapott
     * paraméterektől. Az, hogy az akció a HELYES paraméterekkel hívja-e meg
     * (gateway név, `RETURN_ROUTE`, a capture token `afterUrl`-je), külön
     * teszt tárgya — itt csak az eredmény (a hash) számít.
     */
    private function genericTokenFactory(string $returnHash = self::RETURN_TOKEN_HASH): GenericTokenFactoryInterface
    {
        $returnToken = $this->createStub(TokenInterface::class);
        $returnToken->method('getHash')->willReturn($returnHash);

        $factory = $this->createStub(GenericTokenFactoryInterface::class);
        $factory->method('createToken')->willReturn($returnToken);

        return $factory;
    }

    /** @return array<string, mixed> */
    private function convert(PaymentInterface $payment, ?GenericTokenFactoryInterface $genericTokenFactory = null): array
    {
        $action = new ConvertPaymentAction($this->urlGenerator());
        $action->setGenericTokenFactory($genericTokenFactory ?? $this->genericTokenFactory());

        $request = new Convert($payment, 'array', $this->token());
        $action->execute($request);

        return $this->typedArray($request->getResult());
    }

    /**
     * A `Convert::getResult()` és a details-tömb kulcsai `mixed`-ként jönnek
     * vissza — ez az egyetlen hely, ahol egy nyers értéket típusra szűkítünk,
     * hogy a `StartData::fromArray()` és a direkt kulcs-elérés is típusosan
     * fusson a teszteken belül.
     *
     * @return array<string, mixed>
     */
    private function typedArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('A teszt egy tömböt várt eredményül.');
        }

        $typed = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $typed[$key] = $item;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function namespaceData(array $result, string $key): array
    {
        return $this->typedArray($result[$key] ?? null);
    }

    /** @param array<string, mixed> $result */
    private function startData(array $result): StartData
    {
        return StartData::fromArray($this->namespaceData($result, Details::REQUEST_KEY));
    }

    public function testItBuildsTheRequestNamespaceThePayumPackageExpects(): void
    {
        $result = $this->convert($this->payment());

        self::assertArrayHasKey(Details::REQUEST_KEY, $result);

        // A StartData::fromArray() a Payum-csomag validációja: ha ez lefut,
        // a leképezés minden kötelező mezőt helyes típussal töltött ki.
        $startData = $this->startData($result);

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
        self::assertSame(1000, $this->startData($this->convert($this->payment()))->total);
    }

    public function testAllFourReturnUrlsArePresentAndCarryTheEvent(): void
    {
        $startData = $this->startData($this->convert($this->payment()));

        self::assertStringContainsString('e=success', $startData->urls->success);
        self::assertStringContainsString('e=fail', $startData->urls->fail);
        self::assertStringContainsString('e=cancel', $startData->urls->cancel);
        self::assertStringContainsString('e=timeout', $startData->urls->timeout);
        // A mintázott, DEDIKÁLT visszatérési token hash-e szerepel a
        // címekben — NEM a capture tokené (lásd R25: a capture token
        // `targetUrl`-je `/payment/capture/{hash}`, a visszatérés viszont a
        // plugin saját route-jára megy, ezért saját tokent kap).
        self::assertStringContainsString(self::RETURN_TOKEN_HASH, $startData->urls->success);
        self::assertStringNotContainsString(self::TOKEN_HASH, $startData->urls->success);
    }

    public function testANullTokenIsLoudNotARawError(): void
    {
        // A `Convert` konstruktora explicit megengedi a null tokent
        // (`?TokenInterface $token = null`), és egy puszta
        // `new Capture($payment)` nyomán induló `Convert` élesben is
        // adhat ilyet. A vendor docblockja ("mindig van token") téves —
        // ez a teszt a valós, mért viselkedést védi: névvel ellátott,
        // kifogható kivétel jár, nem egy nyers `Error`.
        $this->expectException(IncompletePaymentException::class);

        $request = new Convert($this->payment(), 'array', null);

        $action = new ConvertPaymentAction($this->urlGenerator());
        $action->setGenericTokenFactory($this->genericTokenFactory());
        $action->execute($request);
    }

    public function testAnEmptyMintedReturnTokenHashIsLoud(): void
    {
        // A capture token hash-e itt már lényegtelen — a visszatérési
        // URL-ek a MINTÁZOTT tokentől kapják a hash-üket (R25). Ha a
        // `GenericTokenFactory` üres hash-sel tér vissza, azt kell hangosan
        // jeleznünk, nem a capture tokenét.
        $this->expectException(IncompletePaymentException::class);

        $this->convert($this->payment(), $this->genericTokenFactory(''));
    }

    public function testAMissingGenericTokenFactoryIsLoud(): void
    {
        // A Payum minden gateway-t a `GenericTokenFactoryExtension`-nel épít
        // fel, ami ezt automatikusan beinjektálja — ha mégis hiányzik, az
        // programozási hiba, és annak is kell tűnnie, nem egy nyers
        // `Error`-nak a `null` metódushíváson.
        $this->expectException(MissingGenericTokenFactoryException::class);

        $action = new ConvertPaymentAction($this->urlGenerator());

        $action->execute(new Convert($this->payment(), 'array', $this->token()));
    }

    public function testItAsksTheGenericTokenFactoryForATokenTargetingTheReturnRouteWithTheCapturesGatewayAndAfterUrl(): void
    {
        // R25 hard-követelménye: a mintázott token ugyanarra a gateway-re és
        // ugyanarra az `afterUrl`-re mutasson, mint a capture token — így a
        // végső átirányítás a Sylius szokásos köszönő/hibaoldalára visz.
        $returnToken = $this->createStub(TokenInterface::class);
        $returnToken->method('getHash')->willReturn(self::RETURN_TOKEN_HASH);

        $factory = $this->createMock(GenericTokenFactoryInterface::class);
        $factory->expects(self::once())
            ->method('createToken')
            ->with('simplepay', self::isInstanceOf(PaymentInterface::class), ConvertPaymentAction::RETURN_ROUTE, [], self::CAPTURE_AFTER_URL)
            ->willReturn($returnToken);

        $this->convert($this->payment(), $factory);
    }

    public function testTheAttemptStartsAtOneForAFreshPayment(): void
    {
        self::assertSame(1, $this->startData($this->convert($this->payment()))->attempt);
    }

    public function testTheAttemptIsOneMoreThanTheLastStartedTransaction(): void
    {
        $payment = $this->payment(details: [
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'TIMEOUT', 'attempt' => 2],
        ]);

        $startData = $this->startData($this->convert($payment));

        self::assertSame(3, $startData->attempt);
        self::assertSame('EZ-2026-0042-17-3', $startData->orderRef);
    }

    public function testTheExistingStateIsCarriedOverSoTheIpnLogSurvives(): void
    {
        $payment = $this->payment(details: [
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'TIMEOUT'],
        ]);

        $result = $this->convert($payment);

        self::assertArrayHasKey(Details::STATE_KEY, $result);
        self::assertSame('T1', $this->namespaceData($result, Details::STATE_KEY)['transactionId']);
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

    public function testItRefusesARequestThatIsNotAConvertRequest(): void
    {
        $action = new ConvertPaymentAction($this->urlGenerator());

        $this->expectException(RequestNotSupportedException::class);

        $action->execute(new \stdClass());
    }

    public function testItRefusesAConvertRequestWhoseModelIsNotASyliusPayment(): void
    {
        $action = new ConvertPaymentAction($this->urlGenerator());

        $this->expectException(RequestNotSupportedException::class);

        $action->execute(new Convert(new \stdClass(), 'array', $this->token()));
    }
}
