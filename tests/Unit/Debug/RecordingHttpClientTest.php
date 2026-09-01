<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Tests\Unit\Debug;

use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use Http\Client\Exception\TransferException;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RecordingHttpClientTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/simplepay-recording-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            self::fail(sprintf('Nem hozható létre a teszt könyvtár: %s', $directory));
        }

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function inner(string $responseBody = '{"ok":true}'): MockHttpClient
    {
        $client = new MockHttpClient();
        $client->addResponse(new Response(200, ['Signature' => 'aláírás'], $responseBody));

        return $client;
    }

    private function request(string $body = '{"orderRef":"X"}'): \Psr\Http\Message\RequestInterface
    {
        $factory = new Psr17Factory();

        return $factory
            ->createRequest('POST', 'https://sandbox.simplepay.hu/payment/v2/refund')
            ->withBody($factory->createStream($body));
    }

    public function testDisabledItRecordsNothingAndStillReturnsTheResponse(): void
    {
        $client = new RecordingHttpClient($this->inner(), $this->directory);

        $response = $client->sendRequest($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $client->recordedFiles());
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testEnabledItWritesTheRawRequestAndResponseBodies(): void
    {
        $client = new RecordingHttpClient($this->inner('{"refundTotal":500}'), $this->directory, enabled: true);

        $client->sendRequest($this->request('{"refundTotal":"500"}'));

        $files = $client->recordedFiles();

        self::assertCount(2, $files);

        $contents = array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $files,
        );

        self::assertContains('{"refundTotal":"500"}', $contents);
        self::assertContains('{"refundTotal":500}', $contents);
    }

    public function testTheFileNamesCarryTheEndpointSoTheyAreIdentifiable(): void
    {
        $client = new RecordingHttpClient($this->inner(), $this->directory, enabled: true);

        $client->sendRequest($this->request());

        foreach ($client->recordedFiles() as $file) {
            self::assertStringContainsString('refund', basename($file));
        }
    }

    public function testTheRequestBodyIsStillReadableByTheInnerClient(): void
    {
        // A PSR-7 stream egyszer olvasható; ha a rögzítés elfogyasztja,
        // a valódi kérés üres törzzsel menne ki. Ez a teszt őrzi, hogy
        // visszatekerjük.
        $inner = $this->inner();
        $client = new RecordingHttpClient($inner, $this->directory, enabled: true);

        $client->sendRequest($this->request('{"orderRef":"X"}'));

        self::assertSame('{"orderRef":"X"}', (string) $inner->getLastRequest()?->getBody());
    }

    public function testTheResponseBodyIsStillReadableByTheCaller(): void
    {
        $client = new RecordingHttpClient($this->inner('{"ok":true}'), $this->directory, enabled: true);

        $response = $client->sendRequest($this->request());

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testTheResponseStreamIsRewoundNotJustSelfHealingOnCast(): void
    {
        // A Nyholm-stream __toString()-je saját maga is visszateker, ezért
        // egy `(string) $body` hívás önmagában nem bizonyítja, hogy a
        // rögzítés visszatekerte a streamet. Ez a teszt nyers getContents()
        // hívással ellenőrzi ugyanezt — enélkül a visszatekerés elhagyása
        // észrevétlen maradna.
        $client = new RecordingHttpClient($this->inner('{"ok":true}'), $this->directory, enabled: true);

        $response = $client->sendRequest($this->request());

        self::assertSame('{"ok":true}', $response->getBody()->getContents());
    }

    public function testTheRequestStreamIsRewoundNotJustSelfHealingOnCast(): void
    {
        // Ugyanaz az indok, mint a válasz-streamnél: nyers getContents()
        // hívással ellenőrizzük, hogy a kérés törzse a hívás után a
        // legelejéről olvasható, nem csak a Stream __toString() saját
        // visszatekerése miatt tűnik annak.
        $inner = $this->inner();
        $client = new RecordingHttpClient($inner, $this->directory, enabled: true);

        $client->sendRequest($this->request('{"orderRef":"X"}'));

        self::assertSame('{"orderRef":"X"}', $inner->getLastRequest()?->getBody()->getContents());
    }

    public function testAFailureOfTheInnerClientIsNotSwallowedByTheRecording(): void
    {
        // A műszer nem takarhatja el a valódi hívás hibáját: ha a belső
        // kliens dob, a rögzítőnek is dobnia kell, különben a hívó egy
        // csendben megsemmisült SimplePay-hívást sikeresként érzékelne.
        $inner = new MockHttpClient();
        $inner->addException(new TransferException('a belső kliens elszállt'));

        $client = new RecordingHttpClient($inner, $this->directory, enabled: true);

        $this->expectException(TransferException::class);

        $client->sendRequest($this->request());
    }

    public function testAWriteFailureIsNotSwallowedItThrows(): void
    {
        // A könyvtárat ez az osztály szándékosan nem hozza létre (lásd az
        // osztály docblockját). Ha valaki elfelejti előre létrehozni, az
        // írás hibája nem tűnhet el nyomtalanul — különben a hívó sikeres
        // rögzítést hinne ott, ahol semmi nem került lemezre.
        $missingDirectory = $this->directory . '/nem-letezik';
        $client = new RecordingHttpClient($this->inner(), $missingDirectory, enabled: true);

        $this->expectException(\RuntimeException::class);

        @$client->sendRequest($this->request());
    }

    public function testItCanBeTurnedOnAtRuntime(): void
    {
        $inner = new MockHttpClient();
        $inner->addResponse(new Response(200, [], '{"a":1}'));
        $inner->addResponse(new Response(200, [], '{"b":2}'));

        $client = new RecordingHttpClient($inner, $this->directory);

        $client->sendRequest($this->request());
        $client->enable();
        $client->sendRequest($this->request());

        self::assertCount(2, $client->recordedFiles(), 'Csak a bekapcsolás utáni hívás rögzül.');
    }
}
