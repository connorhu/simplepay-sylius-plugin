<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Resources\Views\Admin;

use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * A gateway_configuration.html.twig sablon lerendereli önmagát — nem csak
 * azt bizonyítjuk, hogy a form-mezők léteznek (ezt a
 * SimplePayGatewayConfigurationTypeTest már megteszi), hanem hogy a sablon
 * egyetlen elágazása (van-e már fizetési mód kód) a helyes ágat futtatja.
 *
 * A `codeconjure_simplepay_ipn` route a Task 8-ban jön létre, itt még nem
 * létezik — ezért az `UrlGeneratorInterface`-t dublőrrel helyettesítjük,
 * a valódi routing rétegtől függetlenül.
 */
final class GatewayConfigurationTemplateTest extends TestCase
{
    private function render(UrlGeneratorInterface $urlGenerator, ?string $paymentMethodCode): string
    {
        $projectRoot = \dirname(__DIR__, 5);

        $loader = new FilesystemLoader([
            $projectRoot . '/src/Resources/views',
            $projectRoot . '/vendor/symfony/twig-bridge/Resources/views/Form',
        ]);

        $twig = new Environment($loader);
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new RoutingExtension($urlGenerator));
        $twig->addRuntimeLoader(new class($twig) implements RuntimeLoaderInterface {
            public function __construct(private readonly Environment $environment)
            {
            }

            public function load(string $class): ?object
            {
                if (FormRenderer::class !== $class) {
                    return null;
                }

                return new FormRenderer(new TwigRendererEngine(['form_div_layout.html.twig'], $this->environment));
            }
        });

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
        ;

        $builder = $formFactory->createBuilder(FormType::class);
        $builder->add('gatewayConfig', FormType::class);
        $builder->get('gatewayConfig')->add('config', SimplePayGatewayConfigurationType::class);
        $view = $builder->getForm()->createView();
        $view->vars['value'] = ['code' => $paymentMethodCode];

        $hookableMetadata = new \stdClass();
        $hookableMetadata->context = new \stdClass();
        $hookableMetadata->context->form = $view;

        return $twig->render('admin/gateway_configuration.html.twig', [
            'hookable_metadata' => $hookableMetadata,
        ]);
    }

    public function testWithoutAPaymentMethodCodeItDoesNotInventAnIpnUrl(): void
    {
        // A create űrlapon nincs kód, tehát a route-generálást meg sem
        // szabad próbálni: egy kitalált URL a vezérlőpanelben csendben
        // elveszett IPN-értesítéseket jelentene.
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $html = $this->render($urlGenerator, null);

        self::assertStringContainsString('codeconjure_simplepay.ipn.heading', $html);
        self::assertStringContainsString('codeconjure_simplepay.ipn.after_save', $html);
        self::assertStringNotContainsString('codeconjure_simplepay.ipn.instructions', $html);
    }

    public function testWithAPaymentMethodCodeItShowsTheIpnUrl(): void
    {
        $code = 'simplepay_huf';
        $fixedUrl = 'https://shop.test/payment/simplepay/ipn/' . $code;

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('codeconjure_simplepay_ipn', ['code' => $code])
            ->willReturn($fixedUrl)
        ;

        $html = $this->render($urlGenerator, $code);

        self::assertStringContainsString('codeconjure_simplepay.ipn.heading', $html);
        self::assertStringContainsString('codeconjure_simplepay.ipn.instructions', $html);
        self::assertStringContainsString($fixedUrl, $html);
        self::assertStringNotContainsString('codeconjure_simplepay.ipn.after_save', $html);
    }
}
