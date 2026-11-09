<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CodeConjure\SyliusSimplePayPlugin\Action\ConvertPaymentAction;
use CodeConjure\SyliusSimplePayPlugin\Checkout\PaymentAlreadySettledListener;
use CodeConjure\SyliusSimplePayPlugin\Command\RefundCommand;
use CodeConjure\SyliusSimplePayPlugin\Controller\IpnController;
use CodeConjure\SyliusSimplePayPlugin\Controller\ReturnController;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\Extension\ForceReconvertOnDeadTransactionExtension;
use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use CodeConjure\SyliusSimplePayPlugin\Twig\SimplePayExtension;
use Psr\Http\Client\ClientInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire(false)
        ->autoconfigure(false)
        ->private();

    // A payum.action tag "factory" attribútuma korlátozza az actiont
    // a simplepay gateway-re. Enélkül MINDEN gateway megkapná a
    // Convert actionünket, és egy PayPal fizetés is SimplePay
    // payloaddá alakulna.
    // public: a Payum "@id" szövegként kapja a taggelt
    // szolgáltatásokat, és a ContainerAwareCoreGatewayFactory csak
    // $container->has($id) esetén oldja fel őket — privát
    // szolgáltatásra ez hamis, és a DB-ből épülő gateway TypeError-ral
    // elbukik (ugyanezért publikusak a Sylius saját Payum actionjei).
    $services->set(ConvertPaymentAction::class)
        ->public()
        ->args([
            service('router'),
        ])
        ->tag('payum.action', ['factory' => 'simplepay']);

    // A payum.extension tag "factory" attribútuma ugyanúgy a
    // simplepay gateway-re korlátozza, mint a payum.action fent.
    // R26 (2. találat): a Sylius CapturePaymentAction csak akkor
    // futtatja újra a Convert-et, ha a GetStatus NEW-t jelent — egy
    // halott tranzakcióra (CANCELLED, TIMEOUT, NOTAUTHORIZED, FRAUD)
    // ez sosem igaz. Ez a kiterjesztés a beágyazott GetStatus-t
    // NEW-ra jelöli át ilyenkor, hogy a vendor kód a Convert-et
    // mégis lefuttassa. Lásd az osztály docblockját.
    // public: ugyanazért, mint a Convert actionnél fent.
    $services->set(ForceReconvertOnDeadTransactionExtension::class)
        ->public()
        ->tag('payum.extension', ['factory' => 'simplepay']);

    $services->set(SimplePayGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => 'simplepay', 'label' => 'SimplePay'])
        ->tag('form.type');

    // DEKORÁTOR, nem külön szolgáltatás. A gateway HTTP-kliensét a
    // codeconjure/simplepay-payum SimplePayGatewayFactoryBuilder kapja
    // meg a konténerből, `Psr\Http\Client\ClientInterface` néven. Ha a
    // RecordingHttpClient csak egy önálló szolgáltatás volna, a
    // RefundCommand `enable()`-je egy olyan példányt kapcsolna be,
    // amin soha nem megy át forgalom — a „record” kapcsoló néma no-op lenne.
    // A dekorálás miatt a gateway kliense MAGA a rögzítő, ami
    // kikapcsolt állapotban tiszta átengedés.
    $services->set(RecordingHttpClient::class)
        ->decorate(ClientInterface::class)
        ->args([
            service(RecordingHttpClient::class . '.inner'),
            '%kernel.project_dir%/var/simplepay',
            false,
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'simplepay']);

    // A kernel.exception figyelőnek csak egy sorrendi kikötése van: a
    // Symfony hibaoldalt előállító ErrorListenere -128 prioritáson fut,
    // tehát az alapértelmezett 0 bőven előtte van.
    $services->set(PaymentAlreadySettledListener::class)
        ->args([
            service('payum'),
            service('doctrine.orm.entity_manager'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception'])
        ->tag('monolog.logger', ['channel' => 'simplepay']);

    $services->set(IpnController::class)
        ->public()
        ->args([
            service('sylius.repository.payment_method'),
            service('sylius.repository.payment'),
            service('payum'),
            service('doctrine.orm.entity_manager'),
            service('payum.converter.reply_to_http_response'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'simplepay']);

    $services->set(ReturnController::class)
        ->public()
        ->args([
            service('payum'),
            service('doctrine.orm.entity_manager'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'simplepay']);

    $services->set(RefundCommand::class)
        ->args([
            service('sylius.repository.order'),
            service('payum'),
            service('doctrine.orm.entity_manager'),
            service(RecordingHttpClient::class),
        ])
        ->tag('console.command');

    $services->set(SimplePayExtension::class)
        ->tag('twig.extension');
};
