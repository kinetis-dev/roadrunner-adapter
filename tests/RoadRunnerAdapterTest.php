<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\StreamScopeLease;
use Kinetis\Http\StreamedResponse;
use Kinetis\Http\TrustedProxies;
use Kinetis\RoadRunnerAdapter\Exception\RoadRunnerAdapterException;
use Kinetis\RoadRunnerAdapter\RoadRunnerAdapter;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;

/**
 * What only `RoadRunnerAdapter::handle()` itself does — capability
 * detection, header folding, the forwarded scheme, the streaming
 * refusal — proven directly against a fabricated
 * `ServerRequestInterface`, with no real `rr` binary in the loop.
 * Everything shared with the other adapters (request/response shape,
 * cookies, identity, the malformed-body 400 and over-limit 413
 * contracts) lives in the runtime conformance suite instead, run against
 * this adapter by {@see RoadRunnerConformanceTest} — this class holds
 * only what a fabricated request can exercise and the shared suite
 * can't.
 *
 * Every request here carries the `rr_parsed_body` attribute a real
 * worker stamps on every request, because {@see request()} puts it
 * there: an adapter that cannot see that attribute refuses the request
 * outright, which is itself one of the behaviors below.
 */
final class RoadRunnerAdapterTest extends TestCase
{
    /** The one peer these cases treat as an edge; anything else is a client. */
    private const string EDGE_ADDRESS = '10.0.0.1';

    public function test_is_persistent(): void
    {
        self::assertTrue((new RoadRunnerAdapter(self::proxies()))->isPersistent());
    }

    /**
     * PSR7Worker's own header mapping presents a repeated header as
     * several separate array values, not the single comma-joined value
     * RFC 9110 makes equivalent — this is the fold that closes that gap,
     * proven directly rather than only through the real-binary
     * conformance suite.
     *
     * Asserted via `getHeader()` (the raw stored value list), not
     * `getHeaderLine()` — the latter already joins on `,` internally
     * regardless of storage, so a test reading it back can't actually
     * tell "the fold happened" apart from "nothing happened and
     * getHeaderLine() did its own joining anyway." Caught by Infection,
     * not by review: the first version of this test used
     * `getHeaderLine()` and kept passing with `foldRepeatedHeaders()`'s
     * whole `foreach` loop removed.
     */
    public function test_folds_a_repeated_header_into_one_comma_joined_value(): void
    {
        $captured = $this->capture(self::request('GET', '/', ['X-Trace' => ['first', 'second']]));

        self::assertSame(['first, second'], $captured->getHeader('X-Trace'));
    }

    /**
     * The one exception to the comma-join: RFC 6265 §5.4 requires
     * multiple `Cookie` header fields to be combined with `; `, the
     * same separator already used between cookie pairs — comma-joining
     * it the way every other repeated header is folded would corrupt
     * cookie parsing downstream.
     */
    public function test_a_repeated_cookie_header_is_folded_with_a_semicolon_not_a_comma(): void
    {
        $captured = $this->capture(self::request('GET', '/', ['Cookie' => ['a=1', 'b=2']]));

        self::assertSame(['a=1; b=2'], $captured->getHeader('Cookie'));
    }

    /**
     * The other half of the same distinction {@see test_folds_a_repeated_header_into_one_comma_joined_value()}
     * makes: a single value must reach the handler as exactly one
     * stored value, not "coincidentally still one value because
     * implode() of a one-element array equals that element" — the
     * latter is indistinguishable from the former via `getHeaderLine()`
     * alone, which is why this asserts `getHeader()` too.
     */
    public function test_a_single_valued_header_is_left_untouched(): void
    {
        $captured = $this->capture(self::request('GET', '/', ['X-Trace' => 'only']));

        self::assertSame(['only'], $captured->getHeader('X-Trace'));
    }

    // --- Capability detection ------------------------------------------

    /**
     * `PSR7Worker::mapRequest()` copies RoadRunner's own
     * `rr_parsed_body` attribute onto the PSR-7 request untouched — this
     * simulates the misconfigured case directly (`raw_body: false`, the
     * one thing this class's own fabricated-request suite can prove
     * without a real `rr` binary; the real end-to-end proof, including
     * that the worker itself survives it, lives in
     * `RoadRunnerConformanceTest::test_a_missing_raw_body_setting_is_detected_rather_than_silently_corrupting_the_request()`).
     */
    public function test_an_already_parsed_body_attribute_throws_instead_of_reparsing_it(): void
    {
        $request = self::request('POST', '/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1')
            ->withAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME, true);

        $handlerRan = false;

        try {
            RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
                $handlerRan = true;

                return new Response(200);
            }, self::proxies());

            self::fail('expected RoadRunnerAdapterException to be thrown');
        } catch (RoadRunnerAdapterException $e) {
            // A literal expected string, not
            // RoadRunnerAdapterException::rawBodyNotEnabled()->getMessage()
            // — comparing the thrown message against a second call to the
            // exact same (possibly mutated) source would make this
            // assertion pass regardless of what the message actually
            // says, since both sides run the identical code.
            self::assertSame(
                'RoadRunner already parsed this form body itself before handing the '
                . 'request to PHP, which means http.raw_body: true is missing from the '
                . 'RoadRunner configuration. Set it in .rr.yaml — see the "Running under '
                . 'RoadRunner" section of docs/runtime-adapters.md.',
                $e->getMessage(),
            );
        }

        self::assertFalse($handlerRan, 'a misconfiguration must be caught before the handler ever runs');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function unreportedCapability(): iterable
    {
        yield 'the attribute absent entirely' => [[]];
        yield 'the attribute carrying something other than a boolean' => [
            [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => 'false'],
        ];
    }

    /**
     * The detection this adapter's `raw_body` requirement rests on works
     * by reading a flag the worker library sets. A request that doesn't
     * carry that flag doesn't mean "raw_body is on" — it means nothing
     * here can tell. Refused, rather than assumed good and silently
     * degrading into re-parsing a body RoadRunner already parsed.
     *
     * @param array<string, mixed> $attributes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreportedCapability')]
    public function test_a_worker_that_does_not_report_the_capability_is_refused_rather_than_assumed_good(array $attributes): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1');

        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $handlerRan = false;

        try {
            RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
                $handlerRan = true;

                return new Response(200);
            }, self::proxies());

            self::fail('expected RoadRunnerAdapterException to be thrown');
        } catch (RoadRunnerAdapterException $e) {
            // The whole message as a literal, for the reason
            // test_an_already_parsed_body_attribute_throws_instead_of_reparsing_it
            // gives: an operator reading this in a worker log has only
            // the text to act on, and it names the package version that
            // fixes it.
            self::assertSame(
                'This RoadRunner worker did not report whether it parsed the request '
                . 'body itself, so http.raw_body cannot be verified. kinetis/roadrunner-adapter '
                . 'requires a spiral/roadrunner-http version that sets the rr_parsed_body '
                . 'request attribute.',
                $e->getMessage(),
            );
        }

        self::assertFalse($handlerRan, 'an unverifiable capability must be caught before the handler ever runs');
    }

    // --- Identity and responses -----------------------------------------

    /**
     * RoadRunner's own listener is plaintext whenever TLS is terminated
     * in front of it, so without this an application behind a load
     * balancer generates `http://` URLs for an `https://` site. The
     * header is honored because the peer that sent it is a configured
     * edge, not because the header was present.
     */
    public function test_a_forwarded_scheme_from_a_trusted_edge_decides_the_uri_scheme(): void
    {
        // The URI's own authority is the internal listener the edge
        // forwarded to, and `Host` is the name the client addressed —
        // the ordinary shape behind a load balancer, and the only shape
        // in which "the scheme changed, the authority didn't" is a
        // statement about anything at all.
        $captured = $this->capture(self::request(
            'GET',
            'http://10.0.0.7:8080/users',
            ['Host' => 'kinetis.test', 'X-Forwarded-Proto' => 'https'],
        ));

        self::assertSame('https', $captured->getUri()->getScheme());
        self::assertSame(['kinetis.test'], $captured->getHeader('Host'), 'the authority the client addressed is untouched');
    }

    /**
     * The same header from a peer that is not an edge. A directly
     * reachable listener is the ordinary RoadRunner deployment, and
     * `X-Forwarded-Proto` is an ordinary header any client can send — so
     * a client that could rewrite the scheme here could decide whether a
     * `Secure` cookie is set and where an OAuth redirect points. It is
     * ignored completely, not partially.
     */
    public function test_a_forwarded_scheme_from_an_untrusted_client_is_ignored(): void
    {
        $captured = $this->capture(self::request(
            'GET',
            'http://kinetis.test/users',
            ['X-Forwarded-Proto' => 'https'],
            remoteAddr: '203.0.113.9',
        ));

        self::assertSame('http', $captured->getUri()->getScheme(), 'a client cannot promote its own request to https');
    }

    /**
     * And the reverse: an untrusted client cannot downgrade either, so a
     * deployment that terminates TLS at the listener stays https no
     * matter what a client claims.
     */
    public function test_a_forwarded_scheme_from_an_untrusted_client_cannot_downgrade_the_request(): void
    {
        $captured = $this->capture(self::request(
            'GET',
            'https://kinetis.test/users',
            ['X-Forwarded-Proto' => 'http'],
            remoteAddr: '203.0.113.9',
        ));

        self::assertSame('https', $captured->getUri()->getScheme());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unreadableForwardedSchemes(): iterable
    {
        yield 'a scheme that is neither http nor https' => ['gopher'];
        yield 'two schemes folded into one header' => ['https, http'];
        yield 'an empty entry beside a real one' => ['https,'];
    }

    /**
     * A trusted proxy that names something other than one scheme is a
     * misconfigured edge, and there is no rule that picks the right
     * answer from two — the first entry is the client's hop under one
     * convention and the last under another. Refused with the same fixed
     * `400` a body that cannot be parsed gets, rather than guessed at or
     * silently ignored, which would leave the request running under a
     * scheme nothing actually chose.
     */
    #[DataProvider('unreadableForwardedSchemes')]
    public function test_an_unreadable_forwarded_scheme_from_a_trusted_edge_is_a_clean_400(string $value): void
    {
        [$response, $logged] = $this->handleCapturingTheLog(
            self::request('GET', 'http://kinetis.test/users', ['X-Forwarded-Proto' => $value]),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE],
            json_decode((string) $response->getBody(), true),
        );

        // The client is told nothing beyond the fixed message, so this
        // entry is the whole of what an operator has to tell a
        // misconfigured edge apart from a body no parser could read.
        self::assertSame(['Rejected request: unreadable-forwarded-header'], $logged);
    }

    /**
     * The exact status/body pairing {@see \Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver}
     * recognizes to report an `AdapterRejection` instead of a successful
     * response — see `RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE`'s
     * own docblock for why this is the one place that pairing is defined.
     */
    public function test_a_streaming_response_is_refused_as_a_real_501_after_the_handler_runs(): void
    {
        $handlerRan = false;
        $streamed = new StreamedResponse(new Response(200), static function () {});

        $response = RoadRunnerAdapter::handle(
            self::request('GET', '/'),
            static function () use (&$handlerRan, $streamed) {
                $handlerRan = true;

                return $streamed;
            },
            self::proxies(),
        );

        self::assertTrue($handlerRan, 'the refusal must happen after the handler runs, not before');
        self::assertSame(501, $response->getStatusCode());
        self::assertSame(
            ['error' => RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE],
            json_decode((string) $response->getBody(), true),
        );
        self::assertEquals(
            ErrorResponse::create(501, RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE)->getBody()->__toString(),
            (string) $response->getBody(),
        );
    }

    /**
     * This worker never writes the body, so the response is abandoned
     * before the refusal goes back: that releases the request scope the
     * Kernel is holding open for the emitter, on the request that
     * created it, rather than leaving it live until the next one arrives.
     */
    public function test_a_streaming_response_is_abandoned_before_the_501_is_returned(): void
    {
        $emitted = false;
        $app = new AppScope();
        $scope = new RequestScope($app);
        $streamed = new StreamedResponse(
            new Response(200),
            static function () use (&$emitted): void {
                $emitted = true;
            },
            new StreamScopeLease($app, $scope, 'GET', '/'),
        );

        $response = RoadRunnerAdapter::handle(
            self::request('GET', '/'),
            static fn (): ResponseInterface => $streamed,
            self::proxies(),
        );

        self::assertTrue($scope->isDisposed(), 'the refusal must settle the stream, not drop it');
        self::assertFalse($emitted, 'this runtime cannot write the body, so it must not run the emitter');
        self::assertSame(501, $response->getStatusCode());
    }

    /**
     * A request shaped the way a real worker delivers one: with the
     * `rr_parsed_body` attribute set, and set to `false`, which is what
     * RoadRunner reports for every request when `http.raw_body: true` is
     * configured as this adapter requires.
     *
     * @param array<string, string|list<string>> $headers
     */
    private static function request(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        string $remoteAddr = self::EDGE_ADDRESS,
    ): ServerRequest {
        return (new ServerRequest($method, $uri, $headers, $body, serverParams: ['REMOTE_ADDR' => $remoteAddr]))
            ->withAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME, false);
    }

    /** The policy every case here runs under: the edge, and nothing else. */
    private static function proxies(): TrustedProxies
    {
        return TrustedProxies::fromList([self::EDGE_ADDRESS]);
    }

    private function capture(ServerRequestInterface $request): ServerRequestInterface
    {
        $captured = null;

        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $seen) use (&$captured): Response {
            $captured = $seen;

            return new Response(200);
        }, self::proxies());

        self::assertInstanceOf(ServerRequestInterface::class, $captured, 'the handler never ran');

        return $captured;
    }

    /**
     * The response, and every entry the adapter wrote while producing
     * it. `error_log()` is left exactly as production calls it — the
     * seam is the `error_log` ini setting, pointed at a file of this
     * test's own for the length of the call, so nothing in the adapter
     * knows a test is running.
     *
     * PHP stamps a timestamp in front of each entry written to a file
     * destination; it is stripped here, since what the adapter controls
     * is the message and only the message.
     *
     * @return array{0: ResponseInterface, 1: list<string>}
     */
    private function handleCapturingTheLog(ServerRequestInterface $request): array
    {
        $log = tempnam(sys_get_temp_dir(), 'kinetis-log-');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $response = $this->handleWithoutHandler($request);
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($log);
        }

        $entries = array_values(array_filter(explode(PHP_EOL, $written), static fn (string $entry): bool => $entry !== ''));

        return [$response, array_map(
            static fn (string $entry): string => (string) preg_replace('/^\[[^\]]*\] /', '', $entry),
            $entries,
        )];
    }

    private function handleWithoutHandler(ServerRequestInterface $request): ResponseInterface
    {
        $handlerRan = false;

        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        }, self::proxies());

        self::assertFalse($handlerRan, 'the handler must not run for a body the adapter refused');

        return $response;
    }
}
