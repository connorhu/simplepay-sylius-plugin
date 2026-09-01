<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Action;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\PaymentMethod;
use CodeConjure\SimplePay\Request\Invoice;
use CodeConjure\SimplePay\Request\Urls;
use CodeConjure\SimplePay\ReturnEvent;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Exception\GatewayMismatchException;
use CodeConjure\SyliusSimplePayPlugin\Exception\IncompletePaymentException;
use CodeConjure\SyliusSimplePayPlugin\Exception\MissingGenericTokenFactoryException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Language\LocaleToLanguageMap;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use CodeConjure\SyliusSimplePayPlugin\Order\OrderReference;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sylius `Payment` → a Payum-csomag `simplepay_request` névtere.
 *
 * EZ AZ AZ OSZTÁLY, AMI SYLIUST ISMER. A Payum-csomag `CaptureAction`-je
 * egy kész, gateway-független tömböt fogyaszt; ami rendelést, vevőt,
 * számlázási címet vagy Sylius pénz-ábrázolást ismer, az mind itt van.
 *
 * A régi implementációban ez a határ elmosódott: a `TransactionPayloadFactory`
 * egyszerre ismerte a Payum `Capture`-t, a Sylius `Order`-t és a SimplePay
 * mezőneveit.
 */
final class ConvertPaymentAction implements ActionInterface, GenericTokenFactoryAwareInterface
{
    public const string RETURN_ROUTE = 'codeconjure_simplepay_return';

    /**
     * A Payum a `GenericTokenFactoryExtension`-en keresztül, minden
     * gateway-hívás előtt beállítja — lásd `setGenericTokenFactory()`.
     * Ezért nem konstruktor-paraméter: az akciót a Payum konténer építi
     * fel a gateway-től függetlenül, a gyárat pedig a gateway adja hozzá.
     */
    private ?GenericTokenFactoryInterface $genericTokenFactory = null;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function setGenericTokenFactory(?GenericTokenFactoryInterface $genericTokenFactory = null): void
    {
        $this->genericTokenFactory = $genericTokenFactory;
    }

    /**
     * Az `instanceof` őrök nem az `assertSupports()`-ot ismétlik meg —
     * azok adják a statikus elemzőnek a típusszűkítést, amit egy típus-
     * annotáció csak állítana, nem bizonyítana. Enélkül egy rossz típusú
     * `$request` egy nyers `TypeError`-t dobna ott, ahol a Payum action
     * lánca `RequestNotSupportedException`-t vár.
     */
    public function execute($request): void
    {
        if (!$request instanceof Convert) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $payment = $request->getSource();

        if (!$payment instanceof PaymentInterface) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $order = $payment->getOrder();

        if (!$order instanceof OrderInterface) {
            throw new IncompletePaymentException('A fizetéshez nem tartozik rendelés.');
        }

        $method = $payment->getMethod();

        if (!$method instanceof PaymentMethodInterface) {
            throw new GatewayMismatchException('A fizetéshez nem tartozik fizetési mód.');
        }

        $settings = GatewayConfigReader::read($method);
        $currency = $this->currency($payment, $settings->currency);

        $details = $payment->getDetails();
        $stateArray = $this->stateArray($details);
        $state = TransactionState::fromArray($stateArray);

        $startData = new StartData(
            orderRef: OrderReference::build(
                $this->orderNumber($order),
                $this->paymentId($payment),
                $state->attempt + 1,
            ),
            total: SyliusAmountConverter::toMinorUnits($this->amount($payment), $currency),
            currency: $currency,
            customerEmail: $this->customerEmail($order),
            invoice: $this->invoice($order),
            urls: $this->urls($request, $payment),
            language: LocaleToLanguageMap::resolve($order->getLocaleCode()),
            // Fixen CARD: a WIRE élőben sosem lett kipróbálva, és az
            // átutalásos folyamat állapotkezelését egyik csomag sem modellezi.
            methods: [PaymentMethod::Card],
            attempt: $state->attempt + 1,
        );

        $result = $details;
        $result[Details::REQUEST_KEY] = $startData->toArray();

        // A meglévő állapot megmarad: az IPN-napló egy korábbi, lejárt
        // próbálkozásból is értékes, és az `attempt` számláló forrása.
        if ([] !== $stateArray) {
            $result[Details::STATE_KEY] = $stateArray;
        }

        $request->setResult($result);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert &&
            'array' === $request->getTo() &&
            $request->getSource() instanceof PaymentInterface;
    }

    private function currency(PaymentInterface $payment, Currency $merchantCurrency): Currency
    {
        $code = $payment->getCurrencyCode();

        if (null === $code || '' === $code) {
            throw new IncompletePaymentException('A fizetéshez nincs pénznem.');
        }

        if ($code !== $merchantCurrency->value) {
            throw new GatewayMismatchException(sprintf(
                'A fizetés pénzneme "%s", a SimplePay merchant viszont "%s"-t fogad. '
                . 'A SimplePay merchant azonosító pénznemhez kötött: több pénznemhez '
                . 'több merchant és több fizetési mód kell.',
                $code,
                $merchantCurrency->value,
            ));
        }

        return $merchantCurrency;
    }

    private function amount(PaymentInterface $payment): int
    {
        return $payment->getAmount() ?? throw new IncompletePaymentException(
            'A fizetéshez nincs összeg.',
        );
    }

    private function orderNumber(OrderInterface $order): string
    {
        $number = $order->getNumber();

        return null === $number || '' === $number
            ? throw new IncompletePaymentException('A rendelésnek nincs száma.')
            : $number;
    }

    private function paymentId(PaymentInterface $payment): int
    {
        $id = $payment->getId();

        return is_int($id)
            ? $id
            : throw new IncompletePaymentException(
                'A fizetésnek még nincs azonosítója — a SimplePay hivatkozás nem állítható elő. '
                . 'A fizetést a capture előtt perzisztálni kell.',
            );
    }

    private function customerEmail(OrderInterface $order): string
    {
        $email = $order->getCustomer()?->getEmail();

        return null === $email || '' === $email
            ? throw new IncompletePaymentException('A rendeléshez nem tartozik vevő e-mail cím.')
            : $email;
    }

    private function invoice(OrderInterface $order): Invoice
    {
        $address = $order->getBillingAddress();

        if (!$address instanceof AddressInterface) {
            throw new IncompletePaymentException('A rendeléshez nem tartozik számlázási cím.');
        }

        return new Invoice(
            name: $this->addressField($address->getFullName(), 'név'),
            // ISO kód, NEM szöveges országnév: az 1. fázis élő kontraktus-
            // tesztje "HU"-t küldött, és a SimplePay elfogadta.
            country: $this->addressField($address->getCountryCode(), 'ország'),
            city: $this->addressField($address->getCity(), 'város'),
            zip: $this->addressField($address->getPostcode(), 'irányítószám'),
            address: $this->addressField($address->getStreet(), 'utca'),
        );
    }

    private function addressField(?string $value, string $label): string
    {
        return null === $value || '' === trim($value)
            ? throw new IncompletePaymentException(sprintf(
                'A számlázási cím "%s" mezője üres, enélkül a SimplePay kérés nem állítható össze.',
                $label,
            ))
            : trim($value);
    }

    /**
     * A négy visszatérési URL nem a capture tokenre mutat: annak
     * `targetUrl`-je a `/payment/capture/{hash}` útvonal, a vevő viszont a
     * plugin saját `codeconjure_simplepay_return` route-jára tér vissza. A
     * `RequestTokenVerifier::isValid()` a célútvonalakat nyers
     * karakterlánc-egyenlőséggel hasonlítja — a kettő sosem egyezne, és a
     * visszatérés minden esetben HTTP 400-at dobna.
     *
     * Ezért itt egy DEDIKÁLT tokent állítunk elő a `GenericTokenFactory`-n
     * keresztül, ami a visszatérés route-jára mutat. A `RequestTokenVerifier`
     * csak az útvonalat nézi, a lekérdezés-stringet nem — így ez az EGY
     * token szolgálja ki mind a négy címet, amik csak az `e` paraméterben
     * térnek el.
     */
    private function urls(Convert $request, PaymentInterface $payment): Urls
    {
        // A `Convert` konstruktora explicit nullable tokent enged
        // (`?TokenInterface $token = null`) — a vendor docblockja ezt
        // tévesen `@return TokenInterface`-ként ígéri, de a
        // `stubs/PayumConvert.stub.php` ezt a PHPStan felé javítja, hogy a
        // guard ne tűnjön holt kódnak. Null token esetén nincs miből
        // visszatérési URL-eket előállítani.
        $captureToken = $request->getToken();

        if (null === $captureToken) {
            throw new IncompletePaymentException(
                'A capture-höz nincs Payum token, ezért a visszatérési címek nem állíthatók elő.',
            );
        }

        $hash = $this->returnToken($captureToken, $payment)->getHash();

        if ('' === $hash) {
            throw new IncompletePaymentException(
                'A visszatérési token hash-e üres, ezért a visszatérési címek nem állíthatók elő.',
            );
        }

        return new Urls(
            success: $this->returnUrl($hash, ReturnEvent::Success),
            fail: $this->returnUrl($hash, ReturnEvent::Fail),
            cancel: $this->returnUrl($hash, ReturnEvent::Cancel),
            timeout: $this->returnUrl($hash, ReturnEvent::Timeout),
        );
    }

    /**
     * A `createToken()` ötödik paramétere (`$afterPath`) a vendor
     * `AbstractTokenFactory::createToken()`-jében kettős szerepű: ha
     * `http`-vel kezdődik, változatlan URL-ként kerül a tokenre, egyébként
     * route névként generálódik. A capture token `getAfterUrl()`-je már
     * teljes, abszolút URL (a Sylius köszönő/hibaoldala), ezért ide
     * változtatás nélkül átadható — a végső átirányítás így a capture
     * folyamattal azonos oldalra visz.
     */
    private function returnToken(TokenInterface $captureToken, PaymentInterface $payment): TokenInterface
    {
        if (null === $this->genericTokenFactory) {
            throw new MissingGenericTokenFactoryException(
                'A GenericTokenFactory nem érkezett meg a Convert-akcióhoz — '
                . 'a visszatérési tokent nem lehet előállítani.',
            );
        }

        return $this->genericTokenFactory->createToken(
            $captureToken->getGatewayName(),
            $payment,
            self::RETURN_ROUTE,
            [],
            $captureToken->getAfterUrl(),
        );
    }

    private function returnUrl(string $hash, ReturnEvent $event): string
    {
        return $this->urlGenerator->generate(
            self::RETURN_ROUTE,
            ['payum_token' => $hash, 'e' => strtolower($event->value)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
