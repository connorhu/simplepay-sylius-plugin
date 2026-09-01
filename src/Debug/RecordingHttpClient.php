<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Debug;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-18 dekorátor, ami a nyers kérés- és válasz-törzseket fájlba menti.
 *
 * EZ A MÉRÉS MŰSZERE. A protokoll-csomagnak két nyitott kérdése van, amit
 * csak valódi forgalomból lehet lezárni:
 *
 *   1. egy SIKERES jóváírás válaszának pontos alakja (ma dokumentációból
 *      származó mezőkészlet, nem mérés),
 *   2. a `detailed: true` lekérdezés extra mezői.
 *
 * A rögzített nyers JSON a protokoll-csomagba kerül fixture-ként — az
 * érzékeny mezők (`customer`, `customerEmail`, `invoice`, `salt`) értéke
 * `"[REDACTED]"`-re cserélve, de a KULCSOKAT megtartva, hogy a fixture
 * bizonyítani tudja, melyik mezőt küldi ténylegesen a SimplePay.
 *
 * ALAPÉRTELMEZÉSBEN KI VAN KAPCSOLVA. A `detailed: true` miatt minden
 * lekérdezés válasza tartalmazza a vevő nevét, e-mail címét és számlázási
 * címét — bekapcsolva ezek lemezre kerülnek. Éles környezetben csak
 * tudatosan, rövid ideig, és a fájlokat utána törölni kell.
 *
 * FONTOS: ezt az osztályt dekorátorként regisztráljuk (decorates:
 * `Psr\Http\Client\ClientInterface`), NEM önálló szolgáltatásként. A 2.
 * rétegbeli `SimplePayGatewayFactoryBuilder` a HTTP klienst a konténerből,
 * pontosan ezen az azonosítón keresztül kapja meg — egy önálló
 * `RecordingHttpClient` szolgáltatás így a kérésúton kívül maradna, és az
 * `enable()` egy olyan példányt kapcsolna be, amelyen soha nem megy át
 * forgalom. A `--record` kapcsoló ekkor néma no-op lenne. Ez az osztály
 * emiatt nem változik, de a bekötés módja innentől nem tetszőleges.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<string> */
    private array $recordedFiles = [];

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $directory,
        private bool $enabled = false,
    ) {
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /** @return list<string> */
    public function recordedFiles(): array
    {
        return $this->recordedFiles;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->enabled) {
            return $this->inner->sendRequest($request);
        }

        $prefix = sprintf(
            '%s/%s-%s',
            rtrim($this->directory, '/'),
            new \DateTimeImmutable()->format('Ymd-His-u'),
            $this->endpoint($request),
        );

        $this->write($prefix . '.req.json', $this->readBody($request->getBody()));

        $response = $this->inner->sendRequest($request);

        $this->write($prefix . '.res.json', $this->readBody($response->getBody()));

        return $response;
    }

    private function endpoint(RequestInterface $request): string
    {
        $segment = basename($request->getUri()->getPath());

        return '' === $segment ? 'unknown' : preg_replace('/[^a-z0-9_-]/i', '', $segment) ?? 'unknown';
    }

    /**
     * A PSR-7 stream egyszer olvasható. Ha nem tekerjük vissza, a valódi
     * kérés üres törzzsel menne ki, a hívó pedig üres választ kapna —
     * a műszer így elrontaná azt, amit mérni akar.
     */
    private function readBody(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $contents = $stream->getContents();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Nem írható a felvételi fájl: %s', $path));
        }

        $this->recordedFiles[] = $path;
    }
}
