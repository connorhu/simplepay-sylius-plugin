<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Form\Type;

use CodeConjure\SyliusSimplePayPlugin\Form\Type\SimplePayGatewayConfigurationType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class SimplePayGatewayConfigurationTypeTest extends TypeTestCase
{
    protected function setUp(): void
    {
        // A TypeTestCase::setUp() csak akkor mockolja a dispatchert, ha még
        // nincs beállítva — itt egy elvárás nélküli stub-bal előzzük meg,
        // hogy a PHPUnit ne jelezzen "no expectations configured" notice-t.
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);

        parent::setUp();
    }

    /** @return list<\Symfony\Component\Form\FormExtensionInterface> */
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    private function form(): FormInterface
    {
        return $this->factory->create(SimplePayGatewayConfigurationType::class);
    }

    public function testItExposesExactlyTheFourSettableFields(): void
    {
        $names = array_keys(iterator_to_array($this->form()));

        sort($names);

        self::assertSame(['currency', 'environment', 'merchant', 'secretKey'], $names);
    }

    public function testItSubmitsIntoThePayumConfigNamespace(): void
    {
        $form = $this->form();

        $form->submit([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ]);

        self::assertTrue($form->isSynchronized());

        /** @var array<string, mixed> $data */
        $data = $form->getData();

        self::assertSame([
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'titok',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ], $data);
    }

    public function testTheEnvironmentOffersOnlySandboxAndProduction(): void
    {
        $choices = $this->form()->get('environment')->getConfig()->getOption('choices');

        self::assertSame(['sandbox', 'production'], array_values((array) $choices));
    }

    public function testTheCurrencyOffersOnlyTheThreeSupportedOnes(): void
    {
        $choices = $this->form()->get('currency')->getConfig()->getOption('choices');

        self::assertSame(['HUF', 'EUR', 'USD'], array_values((array) $choices));
    }

    public function testTheLegacyLocaleAndAllowedCurrenciesFieldsAreGone(): void
    {
        // A nyelv a rendelésből jön, a pénznem pedig merchant-hez kötött,
        // nem lista. A régi űrlap mindkettőt megkérdezte.
        $form = $this->form();

        self::assertFalse($form->has('locale'));
        self::assertFalse($form->has('allowed_currencies'));
        self::assertFalse($form->has('sandbox'));
    }

    public function testAllFourFieldsRejectAnEmptyValue(): void
    {
        // A gateway configról nem derül ki hangos hibából, hogy az admin
        // üresen hagyta a mezőt — csak akkor, amikor a SimplePay API
        // elutasítja a kérést. A NotBlank ezt itt, mentés előtt jelzi.
        $form = $this->form();

        $form->submit([
            'merchant' => '',
            'secretKey' => '',
            'environment' => '',
            'currency' => '',
        ]);

        self::assertFalse($form->isValid());

        foreach (['merchant', 'secretKey', 'environment', 'currency'] as $field) {
            self::assertGreaterThan(
                0,
                $form->get($field)->getErrors()->count(),
                sprintf('A(z) "%s" mezőnek hibát kellene jeleznie üres értékre.', $field),
            );
        }
    }
}
