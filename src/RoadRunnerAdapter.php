<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter;

use Kinetis\Http\MediaType;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\RoadRunnerAdapter\Exception\MalformedRequestBodyException;
use Kinetis\RoadRunnerAdapter\Exception\RoadRunnerAdapterException;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\StreamableResponseInterface;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\StreamedPart;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\Worker;
use Throwable;

/**
 * Bridges RoadRunner's Goridge/PSR7Worker protocol to the Kernel, so the
 * same application code that runs under FrankenPHP or FPM also runs
 * behind `rr serve` without changes.
 *
 * Requires `http.raw_body: true` in the RoadRunner configuration —
 * confirmed directly against roadrunner-server/http's real Go source
 * (`config/config.go`'s `RawBody` field, `handler/handler.go`): by
 * default RoadRunner parses `multipart/form-data`/
 * `application/x-www-form-urlencoded` bodies itself, in Go, before the
 * PHP worker is ever invoked, and a body it can't parse never reaches
 * PHP at all — the client gets a plain, non-JSON error response instead
 * of {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}'s required
 * shape. `raw_body: true` disables that Go-side parsing entirely, so
 * every body — well-formed or not — reaches this adapter's own userland
 * parser untouched, the same shape `Kinetis\BrefAdapter\BrefLambdaAdapter`
 * already needs for the identical reason: a request body here is one
 * in-memory string with no live `php://input` stream behind it, so PHP
 * 8.4's `request_parse_body()` (what `FrankenPhpAdapter`/`FpmAdapter` use
 * for the same problem in core) can't help — it's stream-bound. Parsing
 * an arbitrary multipart string needs `riverline/multipart-parser`, and
 * pulling that into every Kinetis install just for a deployment target
 * most consumers don't use isn't worth it — the same reasoning that
 * keeps this adapter in its own package rather than core. A missing
 * `raw_body: true` doesn't fail silently: {@see assertRawBodyEnabled()}
 * detects it from the request itself and throws a clear configuration
 * error rather than letting this adapter re-parse a body RoadRunner
 * already parsed.
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
 * Two real, environment-specific header differences from
 * `SuperglobalsBridge`/`BrefLambdaAdapter`, both confirmed directly
 * against `spiral/roadrunner-http`'s real source rather than assumed:
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
 * The one thing this *isn't* is untested: the shared conformance
 * suite's own order-sensitive assertion is excluded from
 * `integration.yml`'s gate for exactly this reason, but
 * `RoadRunnerConformanceTest::test_cookie_values_survive_regardless_of_order()`
 * still proves the values themselves — both cookies, correctly named,
 * correctly valued — arrive every time.
 *
 * A form body has no size limit enforced before this class parses it —
 * see {@see assertFormBodyWithinLimit()}'s own docblock for what that
 * covers and, more importantly, what it can't: an undeclared-length
 * (chunked) body needs RoadRunner's own `http.max_request_size`, which
 * is required, not optional, for exactly that reason. See
 * docs/runtime-adapters.md's "`http.max_request_size` is the real
 * defense against an oversized body" section for the full reasoning,
 * and `RoadRunnerConformanceTest::test_an_oversized_chunked_body_is_rejected_by_road_runners_own_limit()`
 * for proof it actually works, not just that it's documented.
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
                $response = self::handle($request, $handler);
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
     * place a body this adapter cannot parse is turned into the
     * framework's own 400 rather than an uncaught exception.
     *
     * Public so it's testable directly against a fabricated
     * `ServerRequestInterface`, without a real `rr` binary in the loop —
     * the same reasoning `BrefLambdaAdapter::handleEvent()` is public for.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public static function handle(ServerRequestInterface $request, callable $handler): ResponseInterface
    {
        $request = self::foldRepeatedHeaders($request);

        try {
            $request = self::applyFormBody($request);
        } catch (MalformedRequestBodyException $e) {
            // Logged, never returned — the same policy as
            // SuperglobalsBridge::handle(): the message may carry a
            // fragment of attacker-controlled input.
            error_log('Malformed request body: ' . $e->getMessage());

            return ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE);
        } catch (BodyTooLargeException $e) {
            // Unlike the malformed-body case, this message is safe to
            // return directly — it names a configured byte count, never
            // request content — the same message MaxBodySizeMiddleware
            // itself returns for the JSON #[Body] path.
            return ErrorResponse::create(413, $e->getMessage());
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

    private static function applyFormBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!MediaType::isFormEncoded($contentType)) {
            return $request;
        }

        self::assertRawBodyEnabled($request);
        self::assertFormBodyWithinLimit($request);

        $body = (string) $request->getBody();

        [$parsedBody, $uploadedFiles] = MediaType::isMultipartFormData($contentType)
            ? self::parseMultipart($contentType, $body)
            : self::parseUrlEncoded($body);

        return $request->withParsedBody($parsedBody)->withUploadedFiles($uploadedFiles);
    }

    /**
     * The one detectable sign that `http.raw_body: true` is missing from
     * the RoadRunner configuration this adapter's own docblock documents
     * as required. `HttpWorker::arrayToRequest()`/`requestFromProto()`
     * (confirmed directly against `spiral/roadrunner-http`'s real
     * source, not assumed) both stamp every request with a
     * `Spiral\RoadRunner\Http\Request::PARSED_BODY_ATTRIBUTE_NAME`
     * attribute carrying the Go side's own `parsed` flag — true only for
     * a form-content-type request the Go side decided to parse itself,
     * which only happens when `raw_body` isn't enabled — and
     * `PSR7Worker::mapRequest()` copies every such attribute onto the
     * PSR-7 request untouched, so it's still readable here.
     *
     * Left undetected, the body this class's own parser would go on to
     * read is not the client's original bytes at all: with `raw_body`
     * unset, `Request::getParsedBody()`'s real source shows the Go side
     * re-serializes the fields it already extracted as a JSON string and
     * hands *that* to PHP as the body — so re-parsing it here would
     * silently produce wrong fields for a url-encoded body (`parse_str()`
     * against JSON text), or a `400` for a multipart one (no real
     * boundary in a JSON string) that never names the real, fixable
     * cause. Thrown rather than returned as a response: this is a
     * deployment misconfiguration, not a per-request client error, so it
     * belongs in {@see run()}'s own `Worker::error()` path — opaque to
     * the client, loud in the worker's own logs, and (per that method's
     * own docblock) never crashes the worker over it.
     */
    private static function assertRawBodyEnabled(ServerRequestInterface $request): void
    {
        if ($request->getAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME) === true) {
            throw RoadRunnerAdapterException::rawBodyNotEnabled();
        }
    }

    /**
     * A form body is fully materialized into one in-memory string by
     * {@see applyFormBody()} — unlike the JSON `#[Body]` path, which
     * `MaxBodySizeMiddleware` already guards, this happens before the
     * Kernel/middleware pipeline exists, so that middleware never sees
     * it. This is defense in depth for a request that *declares* its
     * size honestly, checked against the same `MAX_BODY_SIZE` env var
     * (and the same default) `MaxBodySizeMiddleware` uses — not a
     * replacement for it. It cannot bound a body with no declared
     * `Content-Length` (or an inaccurate one): a `SizeLimitedStream`
     * only helps when something reads the body incrementally, and
     * RoadRunner has already handed this adapter the whole thing as one
     * string by the time `handle()` runs. That case is RoadRunner's own
     * `http.max_request_size` to close — see docs/runtime-adapters.md.
     */
    private static function assertFormBodyWithinLimit(ServerRequestInterface $request): void
    {
        $declaredLength = $request->getHeaderLine('Content-Length');

        if ($declaredLength === '' || !ctype_digit($declaredLength)) {
            return;
        }

        $maxBytes = self::maxFormBodyBytes();

        if ((int) $declaredLength > $maxBytes) {
            throw BodyTooLargeException::exceeds($maxBytes);
        }
    }

    private static function maxFormBodyBytes(): int
    {
        $configured = getenv('MAX_BODY_SIZE');

        if ($configured !== false && ctype_digit($configured)) {
            return (int) $configured;
        }

        return 2_097_152;
    }

    /**
     * riverline/multipart-parser expects a stream carrying the
     * Content-Type header (for boundary detection) followed by a blank
     * line, then the body — the shape of one raw HTTP part — so the
     * header is prepended back on before parsing.
     *
     * No explicit rewind() before handing the stream to StreamedPart —
     * confirmed directly against its real source, not assumed: its own
     * constructor already calls rewind() unconditionally, so a second
     * one here would be genuinely redundant, not defensive.
     *
     * @return array{0:array<string,string>,1:array<string,UploadedFile>}
     */
    private static function parseMultipart(string $contentType, string $body): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw RoadRunnerAdapterException::couldNotOpenTempStream();
        }

        fwrite($stream, "Content-Type: {$contentType}\r\n\r\n" . $body);

        $fields = [];
        $files = [];

        // riverline reports an unusable body — no boundary it can find,
        // a truncated part — as a LogicException. Without this it would
        // escape to run()'s generic catch and be reported as a
        // worker-level error, not the client's own malformed input.
        try {
            $parts = (new StreamedPart($stream))->getParts();
        } catch (LogicException $e) {
            throw MalformedRequestBodyException::unparseableMultipart($e->getMessage());
        }

        foreach ($parts as $part) {
            $name = $part->getName();

            if ($name === null) {
                continue;
            }

            if ($part->isFile()) {
                $contents = $part->getBody();
                $files[$name] = new UploadedFile(
                    Stream::create($contents),
                    strlen($contents),
                    UPLOAD_ERR_OK,
                    $part->getFileName(),
                    $part->getMimeType(),
                );

                continue;
            }

            $fields[$name] = $part->getBody();
        }

        return [$fields, $files];
    }

    /**
     * @return array{0:array<string,string>,1:array<never,never>}
     */
    private static function parseUrlEncoded(string $body): array
    {
        parse_str($body, $fields);

        /** @var array<string,string> $fields */
        return [$fields, []];
    }
}
