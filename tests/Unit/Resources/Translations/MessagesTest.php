<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Resources\Translations;

use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A `messages.hu.yaml` a Task 7 admin űrlapjának és sablonjának, valamint
 * ennek a tasknak a rendelés-oldali sablonjának a SZERZŐDÉSE.
 *
 * Az összes eddigi sablon- és form-teszt a `TranslationExtension`-t valódi
 * fordító nélkül futtatja — az ilyen tesztek a fordítási KULCSOT írják ki
 * kimenetként, tehát egyáltalán nem veszik észre, ha egy kulcs hiányzik
 * ebből a fájlból. Ez a teszt magát a fájlt olvassa be, és összeveti azzal
 * a kulcslistával, amit a form típus és a két sablon ténylegesen használ —
 * mérve rögzítve (`grep`-pel a forráson), nem feltételezve.
 */
final class MessagesTest extends TestCase
{
    /** @return array<string, string> */
    private function messages(): array
    {
        $path = \dirname(__DIR__, 4) . '/src/Resources/translations/messages.hu.yaml';
        $parsed = Yaml::parseFile($path);

        self::assertIsArray($parsed);

        return self::flatten($parsed);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $flatKey = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $flatKey);

                continue;
            }

            self::assertIsString($value, sprintf('A(z) "%s" fordítási kulcs értékének stringnek kell lennie.', $flatKey));
            $flat[$flatKey] = $value;
        }

        return $flat;
    }

    /**
     * A `SimplePayGatewayConfigurationType` (Task 7) ezeket a kulcsokat adja
     * mezőlabelnek és help szövegnek — ha bármelyik hiányzik, az admin a
     * nyers kulcsot látná mező-feliratként.
     */
    public function testCoversTheKeysTheGatewayConfigurationFormUses(): void
    {
        $messages = $this->messages();

        foreach ([
            'codeconjure_simplepay.form.merchant',
            'codeconjure_simplepay.form.merchant_help',
            'codeconjure_simplepay.form.secret_key',
            'codeconjure_simplepay.form.secret_key_help',
            'codeconjure_simplepay.form.environment',
            'codeconjure_simplepay.form.currency',
            'codeconjure_simplepay.form.currency_help',
        ] as $key) {
            self::assertArrayHasKey($key, $messages, sprintf('Hiányzik a(z) "%s" kulcs.', $key));
            self::assertNotSame('', trim($messages[$key]), sprintf('A(z) "%s" kulcs értéke nem lehet üres.', $key));
        }

        // A `choice_label` az `Environment` enum minden esetéhez épít egy
        // "codeconjure_simplepay.environment.<value>" kulcsot — mindegyiknek
        // léteznie kell, nem csak az elsőnek.
        foreach (Environment::cases() as $environment) {
            $key = 'codeconjure_simplepay.environment.' . $environment->value;

            self::assertArrayHasKey($key, $messages, sprintf('Hiányzik a(z) "%s" kulcs.', $key));
        }
    }

    /**
     * A `gateway_configuration.html.twig` (Task 7) ezeket a kulcsokat
     * használja az IPN-cím dobozban.
     */
    public function testCoversTheKeysTheGatewayConfigurationTemplateUses(): void
    {
        $messages = $this->messages();

        foreach ([
            'codeconjure_simplepay.ipn.heading',
            'codeconjure_simplepay.ipn.instructions',
            'codeconjure_simplepay.ipn.after_save',
        ] as $key) {
            self::assertArrayHasKey($key, $messages, sprintf('Hiányzik a(z) "%s" kulcs.', $key));
            self::assertNotSame('', trim($messages[$key]), sprintf('A(z) "%s" kulcs értéke nem lehet üres.', $key));
        }
    }

    /**
     * Az `order_show_payment.html.twig` (ez a task) ezeket a kulcsokat
     * használja a SimplePay-kártyához és az IPN-napló táblázatához.
     */
    public function testCoversTheKeysTheOrderShowPaymentTemplateUses(): void
    {
        $messages = $this->messages();

        foreach ([
            'codeconjure_simplepay.admin.heading',
            'codeconjure_simplepay.admin.repeat_warning',
            'codeconjure_simplepay.admin.transaction_id',
            'codeconjure_simplepay.admin.status',
            'codeconjure_simplepay.admin.environment',
            'codeconjure_simplepay.admin.last_ipn',
            'codeconjure_simplepay.admin.ipn_log',
            'codeconjure_simplepay.admin.received_at',
            'codeconjure_simplepay.admin.outcome',
            'codeconjure_simplepay.admin.repeat_count',
        ] as $key) {
            self::assertArrayHasKey($key, $messages, sprintf('Hiányzik a(z) "%s" kulcs.', $key));
            self::assertNotSame('', trim($messages[$key]), sprintf('A(z) "%s" kulcs értéke nem lehet üres.', $key));
        }

        // A napló minden sora "codeconjure_simplepay.outcome.<value>" kulcsot
        // épít az `IpnOutcome` enum értékéből — mindegyik esetnek kell
        // fordítással rendelkeznie, különben egy adott kimenetelű sor a
        // nyers kulcsot mutatná.
        foreach (IpnOutcome::cases() as $outcome) {
            $key = 'codeconjure_simplepay.outcome.' . $outcome->value;

            self::assertArrayHasKey($key, $messages, sprintf('Hiányzik a(z) "%s" kulcs.', $key));
        }
    }
}
