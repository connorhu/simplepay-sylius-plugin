<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Integration;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use Payum\Core\Bridge\Symfony\Security\TokenFactory;
use Payum\Core\Model\Identity;
use Payum\Core\Model\Token;
use Payum\Core\Registry\StorageRegistryInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactory;
use Payum\Core\Security\TokenInterface;
use Payum\Core\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * R25 (Finding 1) bizonyítéka: a `ConvertPaymentAction` a VALÓDI Payum
 * `GenericTokenFactory` + `TokenFactory` (`Payum\Core\Bridge\Symfony\Security`)
 * párossal is a capture token `afterUrl`-jével megegyező `afterUrl`-t
 * hordozó tokent mintáz.
 *
 * Ez szándékosan NEM egy dublon `GenericTokenFactoryInterface`-t hív:
 * `tests/Unit/Action/ConvertPaymentActionTest.php` már bizonyítja, hogy az
 * akció a HELYES paraméterekkel hívja meg a gyárat. Az itt bizonyítandó
 * kérdés más: hogy a vendor `AbstractTokenFactory::createToken()` az
 * `afterPath` ötödik paramétert (ami itt már egy teljes, `http`-vel kezdődő
 * URL) VÁLTOZATLANUL `afterUrl`-ként állítja-e be a mintázott tokenen —
 * ezt csak a valódi vendor kóddal lehet bizonyítani, egy dublóval nem.
 *
 * A tokenek tárolását és a Sylius `Payment` azonosítását egyszerű
 * dublokkal helyettesítjük: ezek a mechanizmus szempontjából
 * érdektelenek, csak a `AbstractTokenFactory`-nak kell egy hely, ahova a
 * tokent elmentse, és egy azonosító, amit a modellhez rendelhet.
 */
final class ReturnTokenMintingTest extends TestCase
{
    private const string CAPTURE_AFTER_URL = 'https://bolt.hu/checkout/thank-you?order=EZ-2026-0042';

    public function testTheMintedReturnTokensAfterUrlEqualsTheCaptureTokensAfterUrl(): void
    {
        $tokenStorage = new InMemoryTokenStorage();

        $paymentStorage = $this->createStub(StorageInterface::class);
        $paymentStorage->method('identify')->willReturn(new Identity(17, PaymentInterface::class));

        $storageRegistry = $this->createStub(StorageRegistryInterface::class);
        $storageRegistry->method('getStorage')->willReturn($paymentStorage);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            /** @param array<string, mixed> $parameters */
            static fn (string $route, array $parameters = []): string => sprintf(
                'https://bolt.hu/payment/simplepay/return?payum_token=%s&e=%s',
                (string) ($parameters['payum_token'] ?? ''),
                (string) ($parameters['e'] ?? ''),
            ),
        );

        // A VALÓDI vendor lánc: Bridge\Symfony\Security\TokenFactory
        // (AbstractTokenFactory) + GenericTokenFactory — ugyanaz, amit a
        // Payum minden gateway-hez felépít (`PayumBuilder::buildGateway()`).
        $tokenFactory = new TokenFactory($tokenStorage, $storageRegistry, $urlGenerator);
        $genericTokenFactory = new GenericTokenFactory($tokenFactory, []);

        $captureToken = $this->createStub(TokenInterface::class);
        $captureToken->method('getHash')->willReturn('capture-token-hash');
        $captureToken->method('getGatewayName')->willReturn('simplepay');
        $captureToken->method('getAfterUrl')->willReturn(self::CAPTURE_AFTER_URL);

        $action = new ConvertPaymentAction($urlGenerator);
        $action->setGenericTokenFactory($genericTokenFactory);

        $request = new Convert($this->payment(), 'array', $captureToken);
        $action->execute($request);

        $result = $request->getResult();
        self::assertIsArray($result);

        $startData = StartData::fromArray($this->typedArray($result[Details::REQUEST_KEY] ?? null));

        $mintedHash = $this->hashFromUrl($startData->urls->success);
        $mintedToken = $tokenStorage->find($mintedHash);

        self::assertInstanceOf(Token::class, $mintedToken);
        // A LÉNYEG: a VALÓDI AbstractTokenFactory a mi `afterPath`
        // argumentumunkat ('http'-tel kezdődik) változtatás nélkül
        // `afterUrl`-ként állította be — nem route névként generálta újra.
        self::assertSame(self::CAPTURE_AFTER_URL, $mintedToken->getAfterUrl());
        self::assertNotSame($captureToken->getHash(), $mintedToken->getHash());
    }

    private function hashFromUrl(string $url): string
    {
        $query = (string) parse_url($url, \PHP_URL_QUERY);
        parse_str($query, $parameters);

        $hash = $parameters['payum_token'] ?? '';

        return is_string($hash) ? $hash : '';
    }

    /**
     * A `Convert::getResult()` `mixed`-et ígér — ez az egyetlen hely, ahol
     * a nyers eredményt tömbre szűkítjük, hogy a `StartData::fromArray()`
     * típusosan fusson.
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

    private function payment(): PaymentInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getFullName')->willReturn('Teszt Elek');
        $address->method('getCountryCode')->willReturn('HU');
        $address->method('getCity')->willReturn('Budapest');
        $address->method('getPostcode')->willReturn('1011');
        $address->method('getStreet')->willReturn('Fő utca 1.');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('vevo@example.com');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn('EZ-2026-0042');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getLocaleCode')->willReturn('hu_HU');
        $order->method('getBillingAddress')->willReturn($address);

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

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn(17);
        $payment->method('getAmount')->willReturn(100000);
        $payment->method('getCurrencyCode')->willReturn('HUF');
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn([]);

        return $payment;
    }
}

/**
 * Minimális, memóriában tartó `StorageInterface` a Payum `Token` modellhez.
 *
 * A `Payum\Core\Storage\StorageInterface` metódusai szándékosan típus
 * nélküliek (a vendor interfész `@param object $model` stílusú
 * docblockokkal dolgozik natív típus helyett) — ez a dublya ezt a
 * szerződést követi, saját natív típus hozzáadása nélkül, hogy a PHP
 * ne utasítsa el kompatibilitási hibával.
 */
final class InMemoryTokenStorage implements StorageInterface
{
    /** @var array<string, Token> */
    private array $tokens = [];

    /** @return object */
    public function create()
    {
        return new Token();
    }

    /**
     * @param object $model
     */
    public function support($model)
    {
        return $model instanceof Token;
    }

    /**
     * @param object $model
     */
    public function update($model)
    {
        if ($model instanceof Token) {
            $this->tokens[$model->getHash()] = $model;
        }
    }

    /**
     * @param object $model
     */
    public function delete($model)
    {
        if ($model instanceof Token) {
            unset($this->tokens[$model->getHash()]);
        }
    }

    /**
     * @param mixed $id
     *
     * @return object|null
     */
    public function find($id)
    {
        return is_string($id) ? ($this->tokens[$id] ?? null) : null;
    }

    /**
     * @param array<array-key, mixed> $criteria
     *
     * @return list<object>
     */
    public function findBy(array $criteria)
    {
        return array_values($this->tokens);
    }

    /**
     * @param object $model
     */
    public function identify($model)
    {
        throw new \LogicException('Ezt a dublya csak `Token`-eket tárol, `identify()`-t nem hívunk rá.');
    }
}
