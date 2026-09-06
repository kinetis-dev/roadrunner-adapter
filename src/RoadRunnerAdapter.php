<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter;

use Kinetis\Http\Exception\UntrustedForwardedHeaderException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\TrustedProxies;
use Kinetis\RoadRunnerAdapter\Exception\RoadRunnerAdapterException;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\StreamableResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\Worker;
use Throwable;

/**
 * Bridges RoadRunner's Goridge/PSR7Worker protocol to the Kernel, so the
 * same application code that runs under FrankenPHP or FPM also runs
 * behind `rr serve` without changes.
 *
 * Requires `http.raw_body: true` in the RoadRunner configuration, which
 * roadrunner-server/http's Go source spells out
 * (`config/config.go`'s `RawBody` field, `handler/handler.go`): by
 * default RoadRunner parses `multipart/form-data`/
 * `application/x-www-form-urlencoded` bodies itself, in Go, before the
 * PHP worker is ever invoked, and a body it can't parse never reaches
 * PHP at all — the client gets a plain, non-JSON error response instead
 * of {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}'s required
 * shape. `raw_body: true` disables that Go-side parsing entirely, so
 * every body — well-formed or not — reaches the Kernel as the bytes the
 * client sent, which is what lets one middleware own the body contract
 * for every runtime. A missing `raw_body: true` doesn't fail silently:
 * {@see assertRawBodyEnabled()} detects it from the request itself and
 * throws a clear configuration error rather than handing on a body
 * RoadRunner has already replaced with its own re-serialization of the
 * fields it extracted.
 *
 * Lives in its own package rather than in core because it needs
 * `spiral/roadrunner-http`, and core carries only the two adapters that
 * must always work with no dependencies at all.
 *
 * `Worker::create()`'s default `interceptSideEffects: true` installs a
 * global output-buffer redirect (`StdoutHandler::register()`) sending
 * every stray `echo`/`header()` call to STDERR instead of the client —
 * required to keep the Goridge binary protocol on STDOUT uncorrupted.
 * This is also why streaming isn't supported here: `StreamedResponse`'s
 * emitter closures (`echo $chunk; flush();`, built for FrankenPHP/FPM)
 * would have their output silently redirected to RoadRunner's own log
 * stream rather than reaching the client, with nothing erroring
 * anywhere. `HttpWorker::respondStream()`'s real generator-based push
 * primitive is a genuinely different, lower-level API than
 * `PSR7Worker::respond()`, and bridging one onto the other needs its own
 * design pass — not attempted here.
 *
 * Two environment-specific header differences from
 * `SuperglobalsBridge`/`BrefLambdaAdapter`, both of them
 * `spiral/roadrunner-http`'s own behavior:
 *
 * - A repeated header arrives as several separate array values, not one
 *   RFC 9110 comma-joined string — {@see foldRepeatedHeaders()} closes
 *   this, so every framework component downstream sees the same shape
 *   regardless of adapter.
 * - A purely-numeric header name is silently dropped before this class
 *   ever sees the request, and cannot be recovered from here. PHP
 *   coerces a numeric string array key (`"123"`) to an `int` one;
 *   `HttpWorker::filterHeaders()` (called from both `arrayToRequest()`
 *   and `requestFromProto()`, on the request-parsing path, before
 *   `PSR7Worker::mapRequest()` ever builds a PSR-7 object) then deletes
 *   it via `!is_string($key)` — a real bug in that library, not a
 *   Kinetis gap, confirmed by reading its source directly rather than
 *   inferred from the symptom. Working around it would mean
 *   reimplementing `HttpWorker`'s own JSON/protobuf request decoding
 *   (both codecs) in this package instead of using `PSR7Worker`, the
 *   same "don't hand-roll a solved wire protocol" reasoning that keeps
 *   this codebase from hand-rolling SQL/AMQP wire protocols elsewhere —
 *   disproportionate to a real-world edge case (a header literally
 *   named with only digits) this narrow. Left as a disclosed, permanent
 *   limitation until the upstream library fixes it; see
 *   docs/runtime-adapters.md for the deployment-facing version of this
 *   note.
 *
 * A third, probabilistic finding, distinct from the two above: cookie
 * order is occasionally not preserved (observed at roughly 1 request in
 * 10 across repeated real runs, not deterministic). `$_COOKIE`-style
 * parsing under FrankenPHP/FPM, and API Gateway's own event field under
 * Bref, both preserve the client's original `Cookie:` header order;
 * RoadRunner's Go side represents cookies as a `map[string]string` on
 * the way to PHP, and Go's map iteration order is randomized by design
 * — a request whose cookies happen to be re-serialized through that map
 * can arrive with a different order than it was sent in. Structurally
 * the same class of Go/PHP-boundary information loss as the header
 * finding above, just probabilistic rather than certain — not chased
 * to a full root-cause trace (which codec, which exact serialization
 * step) given how rarely it triggers and that this package's own
 * request handling has nothing to do with the reordering either way.
 * The one thing this *isn't* is untested: `RoadRunnerDriver` declares
 * that this environment does not preserve cookie order, and the shared
 * conformance suite asserts against that declaration — the names and
 * values on every run, the order only where an environment can keep it
 * — so the whole suite runs here unfiltered.
 *
 * This adapter hands the body on raw; `Kinetis\Http\Form\FormLimits`
 * is applied by the Kernel's own `RequestBodyMiddleware`, the same
 * ceilings and the same refusals every other runtime delivers its bodies
 * to. Those apply after delivery, not before it: RoadRunner has already
 * read the whole body into memory as one string by the time any PHP here
 * runs, so an undeclared-length (chunked) body has to be bounded by
 * RoadRunner's own `http.max_request_size`, which is required rather
 * than optional for exactly that reason. See docs/runtime-adapters.md's
 * "`http.max_request_size` is the real defense against an oversized
 * body" section, and
 * `RoadRunnerConformanceTest::test_an_oversized_chunked_body_is_rejected_by_road_runners_own_limit()`
 * for proof it works rather than merely being documented.
 */
final class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    /**
     * The exact body {@see handle()} sends for a
     * {@see StreamableResponseInterface} result, at status 501 — a real,
     * ordinary HTTP response `RoadRunnerDriver` (the conformance suite's
     * own driver) recognizes by this exact pairing to report an
     * {@see \Kinetis\Testing\Runtime\AdapterRejection} instead of a
     * successful {@see \Kinetis\Testing\Runtime\WireResponse}, since
     * nothing else distinguishes "the adapter deliberately refused this"
     * from "the handler genuinely returned a 501" on the wire.
     */
    public const string STREAMING_NOT_SUPPORTED_MESSAGE = 'RoadRunnerAdapter cannot emit a streaming response.';

    public function __construct(
        private readonly TrustedProxies $trustedProxies,
    ) {}

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    #[\Override]
    public function run(callable $handler): void
    {
        $worker = Worker::create();
        $factory = new Psr17Factory();
        $psr7Worker = new PSR7Worker($worker, $factory, $factory, $factory);

        while (($request = $psr7Worker->waitRequest()) !== null) {
            try {
                $response = self::handle($request, $handler, $this->trustedProxies);
            } catch (Throwable $e) {
                // Deliberately not FrankenPhpAdapter's "let it propagate"
                // convention: confirmed against roadrunner-server/http's
                // real Go handler that Worker::error() (an ERROR-framed
                // Goridge reply) becomes a clean error response to *this*
                // client while the worker process stays alive to serve
                // the next request. Letting this propagate here would
                // kill the whole persistent worker over one bad request
                // — a materially worse failure than any other adapter
                // risks, since it costs AppScope's warm state until the
                // supervisor respawns it.
                $worker->error($e::class . ': ' . $e->getMessage());

                continue;
            }

            $psr7Worker->respond($response);
        }
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * One request, from the raw PSR-7 request `PSR7Worker::waitRequest()`
     * built to the response to hand to `respond()` — the RoadRunner
     * counterpart of `SuperglobalsBridge::handle()`, and like it the one
     * place a failure that happens before the Kernel exists is turned
     * into the framework's own response rather than an uncaught
     * exception.
     *
     * Public so it's testable directly against a fabricated
     * `ServerRequestInterface`, without a real `rr` binary in the loop —
     * the same reasoning `BrefLambdaAdapter::handleEvent()` is public for.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public static function handle(
        ServerRequestInterface $request,
        callable $handler,
        TrustedProxies $trustedProxies,
    ): ResponseInterface {
        // Before anything reads the request: this adapter's own
        // requirements on the worker library and the server
        // configuration, checked against what they actually delivered.
        self::assertRawBodyEnabled($request);

        $request = self::foldRepeatedHeaders($request);

        try {
            $request = self::withForwardedScheme($request, $trustedProxies);
        } catch (UntrustedForwardedHeaderException) {
            error_log('Rejected request: unreadable-forwarded-header');

            return ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE);
        }

        $response = $handler($request);

        if ($response instanceof StreamableResponseInterface) {
            // A real, ordinary HTTP response — not Worker::error() —
            // so a client (and the runtime conformance suite's own
            // driver) sees a clean, recognizable refusal rather than an
            // opaque worker-level failure. See this class's own
            // docblock for why streaming isn't supported here.
            return ErrorResponse::create(501, self::STREAMING_NOT_SUPPORTED_MESSAGE);
        }

        return $response;
    }

    /**
     * `PSR7Worker::mapRequest()` maps a repeated header to several
     * separate array values, not the single comma-joined value RFC 9110
     * §5.3 makes equivalent — confirmed directly against its real
     * source, and a real, observable difference from
     * `SuperglobalsBridge`/`BrefLambdaAdapter`, both of which already
     * hand the framework one joined string. Folded here, once, up front,
     * so every framework component downstream of this method sees the
     * same shape regardless of which adapter is running.
     *
     * `Cookie` is the one header this must not comma-join: RFC 6265
     * §5.4 requires multiple `Cookie` header fields to be combined with
     * `; ` — the same separator already used *between* cookie pairs
     * inside a single `Cookie` header — not the `, ` every other
     * repeated header gets. A well-behaved client sends at most one
     * `Cookie` header, but HTTP/2 explicitly permits (and RFC 7540
     * §8.1.2.5 recommends, for better compression) splitting it into
     * several — a real case, not a hypothetical one. Comma-joining it
     * the same way as every other header would corrupt cookie parsing.
     */
    private static function foldRepeatedHeaders(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach ($request->getHeaders() as $name => $values) {
            if (count($values) > 1) {
                $separator = strcasecmp($name, 'Cookie') === 0 ? '; ' : ', ';
                $request = $request->withHeader($name, implode($separator, $values));
            }
        }

        return $request;
    }

    /**
     * `PSR7Worker` builds the URI from what RoadRunner's own listener
     * saw, which is plaintext whenever TLS is terminated in front of it
     * — the ordinary deployment. `X-Forwarded-Proto` is how the thing
     * terminating it says so, and core's superglobals bridge already
     * honors it through PSR-7's own server-request creation, so an
     * application generating an absolute URL has to get the same answer
     * here. Trusted for the same reason the client address is: the edge
     * sets it.
     */
    private static function withForwardedScheme(ServerRequestInterface $request, TrustedProxies $trustedProxies): ServerRequestInterface
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : null;

        $forwarded = $trustedProxies->forwardedScheme($remoteAddr, $request->getHeaderLine('X-Forwarded-Proto'));

        if ($forwarded === null) {
            return $request;
        }

        return $request->withUri($request->getUri()->withScheme($forwarded), preserveHost: true);
    }

    /**
     * The one detectable sign of how RoadRunner is configured, and the
     * one this adapter's own requirements rest on.
     * `HttpWorker::arrayToRequest()`/`requestFromProto()` both stamp
     * *every* request with a
     * `Spiral\RoadRunner\Http\Request::PARSED_BODY_ATTRIBUTE_NAME`
     * attribute carrying the Go side's own `parsed` flag — true only for
     * a form-content-type request the Go side decided to parse itself,
     * which only happens when `raw_body` isn't enabled — and
     * `PSR7Worker::mapRequest()` copies every such attribute onto the
     * PSR-7 request untouched, so it's still readable here.
     *
     * Both halves are checked, and on every request rather than only on
     * a form one. `true` is the misconfiguration this adapter's own
     * docblock documents: the body it would go on to read is not the
     * client's original bytes at all but RoadRunner's JSON
     * re-serialization of the fields it already extracted, so re-parsing
     * it would silently produce wrong fields for a url-encoded body
     * (`parse_str()` against JSON text) or a `400` for a multipart one
     * (no real boundary in a JSON string) that never names the real,
     * fixable cause. Anything that is not `false` — the attribute
     * absent, or carrying something other than a boolean — means the
     * signal this detection depends on is not there, so the
     * configuration cannot be verified at all; that is refused rather
     * than assumed good, because assuming it good is exactly how the
     * first case would go undetected.
     *
     * Thrown rather than returned as a response: this is a deployment
     * problem, not a per-request client error, so it belongs in
     * {@see run()}'s own `Worker::error()` path — opaque to the client,
     * loud in the worker's own logs, and (per that method's own
     * docblock) never crashing the worker over it.
     */
    private static function assertRawBodyEnabled(ServerRequestInterface $request): void
    {
        $parsed = $request->getAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME);

        if ($parsed === true) {
            throw RoadRunnerAdapterException::rawBodyNotEnabled();
        }

        if ($parsed !== false) {
            throw RoadRunnerAdapterException::rawBodyUndetectable();
        }
    }
}
