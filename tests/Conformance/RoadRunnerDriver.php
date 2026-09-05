<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests\Conformance;

use Kinetis\RoadRunnerAdapter\Exception\RoadRunnerAdapterException;
use Kinetis\RoadRunnerAdapter\RoadRunnerAdapter;
use Kinetis\Testing\FreePort;
use Kinetis\Testing\Runtime\AdapterRejection;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\Outcome;
use Kinetis\Testing\Runtime\ResponseSpec;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Kinetis\Testing\Runtime\WireRequest;
use Kinetis\Testing\Runtime\WireResponse;
use RuntimeException;

/**
 * Drives RoadRunnerAdapter through a real, spawned `rr serve` process —
 * no in-process shortcut exists the way Bref's LambdaDriver has one
 * (driving BrefLambdaAdapter::handleEvent() directly against an
 * already-decoded event): a RoadRunner request/response only ever exist
 * as the real Goridge wire protocol between `rr` and a real PHP worker
 * process, so this is structurally close to kinetis/framework's own
 * SuperglobalsDriver — real HTTP/1.1 bytes over a real socket — rather
 * than an in-process call.
 *
 * {@see isBinaryAvailable()} is the one thing every test using this
 * driver checks first: it needs a real `rr` binary at
 * {@see binaryPath()}, fetched via `vendor/bin/rr get-binary`
 * (spiral/roadrunner-cli, a require-dev dependency), which is not
 * present by default and is never committed.
 */
final class RoadRunnerDriver implements RuntimeAdapterDriver
{
    private const string CLIENT_IP = '127.0.0.1';

    /** @var resource|null */
    private $server = null;

    private ?string $configPath = null;

    private function __construct(
        private readonly string $hostPort,
        private readonly string $stateDir,
        private readonly string $binaryPath,
    ) {}

    public static function spawn(): self
    {
        $stateDir = sys_get_temp_dir() . '/kinetis-roadrunner-conformance-' . bin2hex(random_bytes(8));
        mkdir($stateDir);

        return new self('127.0.0.1:' . FreePort::reserve(), $stateDir, self::binaryPath());
    }

    /**
     * The real `rr` binary this driver needs, fetched via
     * `vendor/bin/rr get-binary --location .` from this package's own
     * root — see this class's own docblock. Not a fixed name PHPUnit can
     * assume exists; {@see isBinaryAvailable()} is what lets the test
     * class turn its absence into a clean skip instead of every test in
     * the suite failing with a confusing "no such file" error.
     */
    public static function binaryPath(): string
    {
        return __DIR__ . '/../../rr';
    }

    public static function isBinaryAvailable(): bool
    {
        return is_file(self::binaryPath());
    }

    /**
     * @param ?int $maxRequestSizeMb sets `http.max_request_size` (real
     *     megabytes, RoadRunner's own unit) — the upstream defense
     *     {@see \Kinetis\RoadRunnerAdapter\RoadRunnerAdapter}'s own class
     *     docblock and docs/runtime-adapters.md document as required for
     *     an undeclared-length (chunked) body, which nothing at the PHP
     *     layer can bound: RoadRunner hands this adapter the whole body
     *     as one already-materialized string. `null` (the default)
     *     leaves RoadRunner's own 1000 MB default in place — every
     *     existing conformance test relies on that being generous enough
     *     to never trigger.
     * @param bool $rawBody `http.raw_body` — `true` (the default, and
     *     what every other conformance test needs) matches the
     *     documented-required configuration. `false` deliberately
     *     misconfigures it, for the one test proving
     *     `RoadRunnerAdapter::assertRawBodyEnabled()` actually detects
     *     the resulting Go-side pre-parsed body rather than silently
     *     re-parsing it.
     */
    public function start(?int $maxRequestSizeMb = null, bool $rawBody = true): void
    {
        $this->configPath = $this->stateDir . '/.rr.yaml';
        $maxRequestSizeLine = $maxRequestSizeMb === null ? '' : "\n  max_request_size: {$maxRequestSizeMb}";
        $rawBodyValue = $rawBody ? 'true' : 'false';

        file_put_contents($this->configPath, <<<YAML
            version: "3"

            server:
              command: "php {$this->workerScript()}"

            http:
              address: {$this->hostPort}
              raw_body: {$rawBodyValue}{$maxRequestSizeLine}
              pool:
                num_workers: 1
            YAML);

        $server = proc_open(
            [$this->binaryPath, 'serve', '-c', $this->configPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                ...getenv(),
                'KINETIS_CONFORMANCE_STATE_DIR' => $this->stateDir,
                // Loopback only — the peer this driver connects from and
                // nothing else, so the shared forwarded-identity case
                // runs against a real trusted edge rather than a policy
                // that trusts everything.
                'TRUSTED_PROXIES' => '127.0.0.1/32,::1/128',
            ],
        );

        if ($server === false) {
            throw new RuntimeException('Could not start the RoadRunner conformance fixture process.');
        }

        $this->server = $server;

        $this->waitForServerReady();
    }

    public function stop(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        foreach (glob($this->stateDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        if ($this->configPath !== null && is_file($this->configPath)) {
            unlink($this->configPath);
        }

        if (is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    #[\Override]
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome
    {
        $id = bin2hex(random_bytes(16));
        [$wire, $span] = $this->exchange($this->rawHttpRequest($request, $response, $id));

        $observedFile = "{$this->stateDir}/{$id}.json";
        $observed = null;

        if (is_file($observedFile)) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($observedFile), true, flags: JSON_THROW_ON_ERROR);
            $observed = ObservedRequest::fromArray($data);
            unlink($observedFile);
        }

        $wireResponse = self::parseResponse($wire, $span);

        return new Outcome($observed, self::asRejectionIfStreamingRefused($wireResponse));
    }

    /**
     * A genuinely chunked (`Transfer-Encoding: chunked`, no
     * `Content-Length` at all) POST — {@see dispatch()}'s own
     * `rawHttpRequest()` always declares a length, so this exists
     * specifically to prove RoadRunner's `http.max_request_size` (see
     * {@see start()}) rejects an oversized body that never declared its
     * real size, the one case nothing at the PHP layer can bound.
     * Carries the same `X-Conformance-Id`/`X-Conformance-Response`
     * headers {@see dispatch()} does and reports whether the fixture
     * ever actually recorded the request — the real signal that
     * matters here, not the HTTP status alone: a rejection that still
     * reaches the fixture (this adapter's own `LogicException` for a
     * missing conformance ID, say, if that header were ever dropped)
     * would also produce a non-2xx status, and a caller checking status
     * alone couldn't tell that apart from RoadRunner's own Go-level
     * `MaxBytesReader` rejection this method exists to prove.
     *
     * @param list<string> $chunks
     * @return array{reachedFixture: bool, status: ?int}
     */
    public function dispatchOversizedChunkedBody(string $path, array $chunks): array
    {
        $id = bin2hex(random_bytes(16));
        $body = '';

        foreach ($chunks as $chunk) {
            $body .= dechex(strlen($chunk)) . "\r\n{$chunk}\r\n";
        }

        $body .= "0\r\n\r\n";

        $raw = "POST {$path} HTTP/1.1\r\n"
            . "Host: {$this->hostPort}\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "X-Conformance-Id: {$id}\r\n"
            . 'X-Conformance-Response: ' . base64_encode(json_encode(ResponseSpec::json(200, '{"ok":true}')->toArray(), JSON_THROW_ON_ERROR)) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;

        $status = null;

        try {
            [$wire] = $this->exchange($raw);

            if (preg_match('~^HTTP/\d\.\d (\d{3})~', $wire, $matches) === 1) {
                $status = (int) $matches[1];
            }
        } catch (RuntimeException) {
            // The connection closing mid-write, or with no bytes back
            // at all — a real rejection, just one with no status line
            // to read.
        }

        $observedFile = "{$this->stateDir}/{$id}.json";
        $reachedFixture = is_file($observedFile);

        if ($reachedFixture) {
            unlink($observedFile);
        }

        return ['reachedFixture' => $reachedFixture, 'status' => $status];
    }

    /**
     * A refused streaming response travels back over the wire as a real,
     * ordinary HTTP response — {@see RoadRunnerAdapter::handle()} returns
     * it, never `Worker::error()` — so the suite's own contract (a
     * genuine refusal is an {@see AdapterRejection}, not a successful
     * {@see WireResponse}) needs this driver to recognize it after the
     * fact, by the exact status/body pairing
     * {@see RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE} produces
     * — nothing else on the wire distinguishes "the adapter deliberately
     * refused this" from "the handler genuinely returned a 501".
     */
    private static function asRejectionIfStreamingRefused(WireResponse $response): WireResponse|AdapterRejection
    {
        if ($response->status !== 501) {
            return $response;
        }

        $expectedBody = json_encode(['error' => RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE], JSON_THROW_ON_ERROR);

        if ($response->body !== $expectedBody) {
            return $response;
        }

        return new AdapterRejection(
            RoadRunnerAdapterException::class,
            RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE,
        );
    }

    #[\Override]
    public function expectedClientIp(): string
    {
        return self::CLIENT_IP;
    }

    #[\Override]
    public function supportsStreaming(): bool
    {
        // See RoadRunnerAdapter's own class docblock: Worker::create()'s
        // StdoutHandler redirect makes StreamedResponse's echo/flush
        // emitter closures silently unreachable, and bridging
        // HttpWorker::respondStream()'s generator-based push primitive
        // onto them is a real, separate design pass, not attempted here.
        return false;
    }

    #[\Override]
    public function unparseableFormRequest(): WireRequest
    {
        // http.raw_body: true (required — see RoadRunnerAdapter's class
        // docblock) means RoadRunner's own parsing never sees this body
        // at all; the failure is this adapter's own parser finding the
        // declared boundary nowhere in it.
        return new WireRequest(
            'POST',
            '/',
            headers: [['Content-Type', 'multipart/form-data; boundary=----XYZ']],
            body: 'not a real multipart body at all',
        );
    }

    #[\Override]
    public function expectedScheme(): string
    {
        // A plain listener; the conformance server terminates no TLS.
        return 'http';
    }

    #[\Override]
    public function preservesNumericHeaderNames(): bool
    {
        // spiral/roadrunner-http's own request decoding drops it before
        // this adapter exists: PHP coerces a numeric string array key to
        // an int, and HttpWorker::filterHeaders()' !is_string($key)
        // filter then deletes it. Confirmed by reading that source
        // directly. Recovering it would mean reimplementing that
        // library's own JSON and protobuf request decoding here; see
        // RoadRunnerAdapter's class docblock and docs/runtime-adapters.md.
        return false;
    }

    #[\Override]
    public function preservesCookieOrder(): bool
    {
        // RoadRunner represents cookies as a Go map[string]string on the
        // way to PHP, and Go randomizes map iteration order by design —
        // observed at roughly 1 request in 10 across repeated real runs.
        // The values themselves are never lost, which is what the shared
        // case asserts either way.
        return false;
    }

    #[\Override]
    public function trustsTheConnectingClient(): bool
    {
        // The worker fixture is started with a loopback-only
        // TRUSTED_PROXIES, which is exactly the peer this driver connects
        // from — see start().
        return true;
    }

    private function workerScript(): string
    {
        return __DIR__ . '/Fixtures/worker.php';
    }

    /**
     * @return array{0: string, 1: ?float}
     */
    private function exchange(string $raw): array
    {
        $socket = @stream_socket_client("tcp://{$this->hostPort}", $errno, $errstr, 5.0);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the RoadRunner conformance fixture at {$this->hostPort}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);
        self::writeAll($socket, $raw);

        $wire = '';
        $bodyStartsAt = null;
        $firstBodyReadAt = null;
        $lastBodyReadAt = null;

        while (!feof($socket)) {
            $chunk = fread($socket, 65_536);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);

                if ($meta['timed_out']) {
                    throw new RuntimeException('Timed out reading from the RoadRunner conformance fixture.');
                }

                continue;
            }

            $wire .= $chunk;

            if ($bodyStartsAt === null) {
                $separator = strpos($wire, "\r\n\r\n");

                if ($separator !== false) {
                    $bodyStartsAt = $separator + 4;
                }
            }

            if ($bodyStartsAt !== null && strlen($wire) > $bodyStartsAt) {
                $now = microtime(true);
                $firstBodyReadAt ??= $now;
                $lastBodyReadAt = $now;
            }
        }

        fclose($socket);

        if ($wire === '') {
            throw new RuntimeException('The RoadRunner conformance fixture closed the connection without a response.');
        }

        return [$wire, $firstBodyReadAt === null || $lastBodyReadAt === null ? null : $lastBodyReadAt - $firstBodyReadAt];
    }

    /**
     * @param resource $socket
     */
    private static function writeAll($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                $reason = $meta['timed_out'] ? 'timed out' : 'the write failed';

                throw new RuntimeException("Could not send the full request to the RoadRunner conformance fixture: {$reason} after {$offset} of {$length} bytes.");
            }

            $offset += $written;
        }
    }

    private function rawHttpRequest(WireRequest $request, ResponseSpec $response, string $id): string
    {
        $target = $request->path . ($request->queryString !== '' ? '?' . $request->queryString : '');

        // The client's own Host when it declared one, this driver's
        // listener otherwise. One Host header either way: two is a
        // malformed request Go's own HTTP server rejects before the
        // worker sees it, and a request that declares an authority is
        // exactly how the suite checks the adapter reads it from the
        // request rather than from wherever it happens to be listening.
        $host = self::declaredHost($request) ?? $this->hostPort;

        $lines = [
            "{$request->method} {$target} HTTP/1.1",
            "Host: {$host}",
            'Connection: close',
            "X-Conformance-Id: {$id}",
            'X-Conformance-Response: ' . base64_encode(json_encode($response->toArray(), JSON_THROW_ON_ERROR)),
        ];

        foreach ($request->headers as [$name, $value]) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }

            $lines[] = "{$name}: {$value}";
        }

        if ($request->cookies !== []) {
            $lines[] = 'Cookie: ' . implode('; ', $request->cookies);
        }

        $declaresLength = array_any($request->headers, static fn (array $pair): bool => strcasecmp($pair[0], 'Content-Length') === 0);

        if (!$declaresLength && ($request->body !== '' || in_array($request->method, ['POST', 'PUT', 'PATCH'], true))) {
            $lines[] = 'Content-Length: ' . strlen($request->body);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $request->body;
    }

    private static function declaredHost(WireRequest $request): ?string
    {
        foreach ($request->headers as [$name, $value]) {
            if (strcasecmp($name, 'Host') === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function parseResponse(string $wire, ?float $bodyArrivalSpan): WireResponse
    {
        $separator = strpos($wire, "\r\n\r\n");

        if ($separator === false) {
            throw new RuntimeException('Malformed HTTP response from the RoadRunner conformance fixture: ' . $wire);
        }

        $head = substr($wire, 0, $separator);
        $body = substr($wire, $separator + 4);
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines);

        if (preg_match('~^HTTP/\d\.\d (\d{3})~', $statusLine, $matches) !== 1) {
            throw new RuntimeException("Malformed status line from the RoadRunner conformance fixture: {$statusLine}");
        }

        $headers = [];
        $setCookies = [];
        $chunked = false;

        foreach ($lines as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2) + [1 => '']);

            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $setCookies[] = $value;

                continue;
            }

            if (strcasecmp($name, 'Transfer-Encoding') === 0 && strcasecmp($value, 'chunked') === 0) {
                $chunked = true;
            }

            $headers[] = [$name, $value];
        }

        return new WireResponse((int) $matches[1], $headers, $setCookies, $chunked ? self::dechunk($body) : $body, $bodyArrivalSpan);
    }

    private static function dechunk(string $body): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);

            if ($lineEnd === false) {
                break;
            }

            $size = hexdec(trim(substr($body, $offset, $lineEnd - $offset)));

            if ($size === 0) {
                break;
            }

            $out .= substr($body, $lineEnd + 2, (int) $size);
            $offset = $lineEnd + 2 + (int) $size + 2;
        }

        return $out;
    }

    private function waitForServerReady(): void
    {
        $deadline = microtime(true) + 30.0;
        $lastSeen = 'no connection';

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://{$this->hostPort}", timeout: 1.0);

            if ($socket !== false) {
                stream_set_timeout($socket, 2);
                self::writeAll($socket, "GET /__conformance/ready HTTP/1.1\r\nHost: {$this->hostPort}\r\nConnection: close\r\n\r\n");
                $statusLine = (string) fgets($socket);
                fclose($socket);

                if (str_starts_with($statusLine, 'HTTP/1.1 204') || str_starts_with($statusLine, 'HTTP/1.0 204')) {
                    return;
                }

                $lastSeen = trim($statusLine) !== '' ? trim($statusLine) : 'an empty response';
            }

            usleep(100_000);
        }

        throw new RuntimeException("The RoadRunner conformance fixture at {$this->hostPort} never answered /__conformance/ready with 204 (last seen: {$lastSeen}).");
    }
}
