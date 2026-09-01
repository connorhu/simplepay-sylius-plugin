<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Form\Type;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A SimplePay gateway admin konfigurációja.
 *
 * Négy mező. A régi űrlap `locale` és `allowed_currencies` mezői megszűntek:
 * a fizetőoldal nyelve a rendelés locale-jából származik, a pénznem pedig
 * NEM lista, hanem egyetlen érték — a SimplePay merchant azonosító
 * pénznemhez kötött, egy merchant egy pénznemet fogad.
 *
 * Az `environment` a régi bool `sandbox` helyett választás: egy
 * `sandbox: false` érték nem mondja meg, hogy „éles" vagy „nem tudjuk".
 */
#[AutoconfigureTag(
    name: 'sylius.gateway_configuration_type',
    attributes: ['type' => 'simplepay', 'label' => 'SimplePay'],
)]
final class SimplePayGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('merchant', TextType::class, [
                'label' => 'codeconjure_simplepay.form.merchant',
                'help' => 'codeconjure_simplepay.form.merchant_help',
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('secretKey', TextType::class, [
                'label' => 'codeconjure_simplepay.form.secret_key',
                'help' => 'codeconjure_simplepay.form.secret_key_help',
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('environment', ChoiceType::class, [
                'label' => 'codeconjure_simplepay.form.environment',
                'choices' => array_column(Environment::cases(), 'value'),
                'choice_label' => static fn (string $value): string => 'codeconjure_simplepay.environment.' . $value,
                'expanded' => true,
                'multiple' => false,
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'codeconjure_simplepay.form.currency',
                'help' => 'codeconjure_simplepay.form.currency_help',
                'choices' => array_column(Currency::cases(), 'value'),
                'choice_label' => static fn (string $value): string => $value,
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'validation_groups' => ['sylius'],
        ]);
    }
}
