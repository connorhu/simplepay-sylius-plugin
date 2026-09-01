<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Exception\UnrepresentableAmountException;
use Payum\Core\Exception\RequestNotSupportedException;
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

        return $token;
    }

    /** @return array<string, mixed> */
    private function convert(PaymentInterface $payment): array
    {
        $request = new Convert($payment, 'array', $this->token());

        new ConvertPaymentAction($this->urlGenerator())->execute($request);

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
        self::assertStringContainsString(self::TOKEN_HASH, $startData->urls->success);
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

        new ConvertPaymentAction($this->urlGenerator())->execute($request);
    }

    public function testAnEmptyTokenHashIsLoud(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getHash')->willReturn('');

        $this->expectException(IncompletePaymentException::class);

        $request = new Convert($this->payment(), 'array', $token);

        new ConvertPaymentAction($this->urlGenerator())->execute($request);
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
