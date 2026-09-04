<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\StreamedResponse;
use Kinetis\RoadRunnerAdapter\Exception\RoadRunnerAdapterException;
use Kinetis\RoadRunnerAdapter\RoadRunnerAdapter;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;

/**
 * What only `RoadRunnerAdapter::handle()` itself does — form-body
 * parsing, header folding, the streaming refusal — proven directly
 * against a fabricated `ServerRequestInterface`, with no real `rr`
 * binary in the loop. Everything shared with the other adapters
 * (request/response shape, cookies, the malformed-body 400 contract)
 * lives in the runtime conformance suite instead, run against this
 * adapter by {@see RoadRunnerConformanceTest} — this class holds only
 * what a fabricated request can exercise and the shared suite can't.
 */
final class RoadRunnerAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new RoadRunnerAdapter())->isPersistent());
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
        $request = new ServerRequest('GET', '/', ['X-Trace' => ['first', 'second']]);

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
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
        $request = new ServerRequest('GET', '/', ['Cookie' => ['a=1', 'b=2']]);

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
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
        $request = new ServerRequest('GET', '/', ['X-Trace' => 'only']);

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['only'], $captured->getHeader('X-Trace'));
    }

    /**
     * The file field is deliberately placed *before* the text field —
     * catching Infection with the reverse order, the file field last:
     * `continue` (skip only the "also store this as a text field" line)
     * and `break` (stop the loop entirely) produce the identical result
     * when the file field happens to be the final part, since there's
     * nothing left to process either way. Putting a real field after it
     * is what makes the two observably different.
     */
    public function test_parses_a_multipart_body_into_parsed_body_and_uploaded_files(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "\xFFPNGDATA\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $request = new ServerRequest(
            'POST',
            '/avatars',
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertNotNull($captured);
        self::assertSame(['name' => 'Alon'], $captured->getParsedBody());
        self::assertArrayHasKey('avatar', $captured->getUploadedFiles());
        self::assertSame('a.png', $captured->getUploadedFiles()['avatar']->getClientFilename());
    }

    public function test_parses_a_url_encoded_body(): void
    {
        $request = new ServerRequest(
            'POST',
            '/search',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'q=kinetis&page=2',
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['q' => 'kinetis', 'page' => '2'], $captured->getParsedBody());
    }

    /**
     * A media type's type and subtype are case-insensitive (RFC 9110
     * §8.3.1), so this header names a form body and picks the multipart
     * parser — the choice between the two parsers is this adapter's own,
     * and a case-sensitive comparison would send these bytes to
     * parse_str() instead.
     */
    public function test_a_mixed_case_form_content_type_is_parsed(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $request = new ServerRequest(
            'POST',
            '/avatars',
            ['Content-Type' => "Multipart/Form-Data; boundary={$boundary}"],
            $body,
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['name' => 'Alon'], $captured->getParsedBody());
    }

    /**
     * A longer media type that merely begins with a form one is a
     * different media type: the body stays raw bytes, and none of the
     * form-body machinery (the raw_body check, the size cap, either
     * parser) runs for it.
     */
    public function test_a_content_type_that_only_looks_like_a_form_type_is_left_untouched(): void
    {
        $request = new ServerRequest(
            'POST',
            '/search',
            ['Content-Type' => 'application/x-www-form-urlencodedevil'],
            'q=kinetis',
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertNull($captured->getParsedBody());
        self::assertSame('q=kinetis', (string) $captured->getBody());
    }

    public function test_a_multipart_body_with_no_usable_boundary_is_a_clean_400_and_the_handler_never_runs(): void
    {
        $request = new ServerRequest(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=----XYZ'],
            'not a real multipart body at all',
        );

        $handlerRan = false;
        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        });

        self::assertFalse($handlerRan);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_form_content_type_is_left_untouched(): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"a":1}');

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertNull($captured->getParsedBody());
        self::assertSame('{"a":1}', (string) $captured->getBody());
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
            new ServerRequest('GET', '/'),
            static function () use (&$handlerRan, $streamed) {
                $handlerRan = true;

                return $streamed;
            },
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
        $request = (new ServerRequest(
            'POST',
            '/',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'a=1',
        ))->withAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME, true);

        $handlerRan = false;

        try {
            RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
                $handlerRan = true;

                return new Response(200);
            });

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
     * Defense in depth for a request that declares its own size
     * honestly — see `assertFormBodyWithinLimit()`'s own docblock for
     * what this does and does not cover. Checked against the *declared*
     * `Content-Length` alone, before the adapter copies or parses the
     * body — RoadRunner has already handed this adapter the whole body
     * as one in-memory string by this point, so there's nothing left to
     * "read" in the FrankenPHP/FPM sense; this check only prevents
     * spending time on something already known to be too large. Proven
     * here by giving a tiny real body a `Content-Length` that lies about
     * being oversized, matching how `MaxBodySizeMiddleware` itself only
     * ever checks the declared header at this same layer.
     */
    public function test_a_form_body_declaring_a_content_length_over_the_limit_is_a_clean_413_and_the_handler_never_runs(): void
    {
        $request = new ServerRequest(
            'POST',
            '/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '3000000',
            ],
            'a=1',
        );

        $handlerRan = false;
        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        });

        self::assertFalse($handlerRan);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            ['error' => BodyTooLargeException::exceeds(2_097_152)->getMessage()],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * The same `MAX_BODY_SIZE` env var `MaxBodySizeMiddleware` reads for
     * the JSON `#[Body]` path — one value covers both.
     */
    public function test_the_form_body_limit_honors_the_max_body_size_env_var(): void
    {
        $original = getenv('MAX_BODY_SIZE');
        putenv('MAX_BODY_SIZE=10');

        try {
            $request = new ServerRequest(
                'POST',
                '/',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => '11',
                ],
                'a=1',
            );

            $response = RoadRunnerAdapter::handle($request, static fn (): Response => new Response(200));

            self::assertSame(413, $response->getStatusCode());
            self::assertSame(
                ['error' => BodyTooLargeException::exceeds(10)->getMessage()],
                json_decode((string) $response->getBody(), true),
            );
        } finally {
            // Restore the real ambient value rather than unconditionally
            // unsetting it — a bare putenv('MAX_BODY_SIZE') would strip
            // a value the running environment genuinely had set, leaking
            // that change into every test that runs after this one in
            // the same process.
            putenv($original === false ? 'MAX_BODY_SIZE' : "MAX_BODY_SIZE={$original}");
        }
    }

    /**
     * A `MAX_BODY_SIZE` set to something that isn't a plain digit string
     * (a typo, a `"2M"`-style shorthand this project doesn't support)
     * must fall back to the real default rather than being coerced —
     * `(int) 'notanumber'` is `0`, which would reject every request
     * regardless of size, a materially worse failure than ignoring the
     * bad value. Distinguishes this from a version that only checks
     * "is the env var set at all," not "is it actually a digit string."
     */
    public function test_a_non_digit_max_body_size_falls_back_to_the_default(): void
    {
        $original = getenv('MAX_BODY_SIZE');
        putenv('MAX_BODY_SIZE=notanumber');

        try {
            $request = new ServerRequest(
                'POST',
                '/',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => '2097153',
                ],
                'a=1',
            );

            $response = RoadRunnerAdapter::handle($request, static fn (): Response => new Response(200));

            self::assertSame(413, $response->getStatusCode());
            self::assertSame(
                ['error' => BodyTooLargeException::exceeds(2_097_152)->getMessage()],
                json_decode((string) $response->getBody(), true),
            );
        } finally {
            putenv($original === false ? 'MAX_BODY_SIZE' : "MAX_BODY_SIZE={$original}");
        }
    }

    /**
     * A `Content-Length` that isn't a pure digit string — this one has a
     * numeric prefix large enough to overflow past the limit if it were
     * cast anyway — must be left to `http.max_request_size`, not rejected
     * here: {@see \Kinetis\RoadRunnerAdapter\RoadRunnerAdapter::assertFormBodyWithinLimit()}'s
     * own docblock is explicit that an inaccurate declared length is out
     * of scope for this check. Distinguishes the guard's `||` from a
     * mutated `&&` (which only skips the check when the header is
     * *both* empty and non-digit, not just non-digit) and from the
     * guard's `return` being removed outright (which would fall through
     * to the same numeric comparison and reject this value) — both
     * would incorrectly reject a body this check is documented not to
     * bound.
     */
    public function test_a_non_digit_content_length_is_not_bounded_by_this_check(): void
    {
        $request = new ServerRequest(
            'POST',
            '/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '9999999999abc',
            ],
            'a=1',
        );

        $handlerRan = false;
        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        });

        self::assertTrue($handlerRan, 'a malformed Content-Length must not be rejected by this check');
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A declared length exactly *equal* to the limit must pass — only
     * strictly *over* it is too large. Distinguishes the `>` comparison
     * from a mutated `>=`.
     */
    public function test_a_content_length_exactly_at_the_limit_is_not_rejected(): void
    {
        $original = getenv('MAX_BODY_SIZE');
        putenv('MAX_BODY_SIZE=10');

        try {
            $request = new ServerRequest(
                'POST',
                '/',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => '10',
                ],
                'a=1',
            );

            $handlerRan = false;
            $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
                $handlerRan = true;

                return new Response(200);
            });

            self::assertTrue($handlerRan, 'a body exactly at the limit must be allowed through');
            self::assertSame(200, $response->getStatusCode());
        } finally {
            putenv($original === false ? 'MAX_BODY_SIZE' : "MAX_BODY_SIZE={$original}");
        }
    }

    public function test_a_form_body_within_the_limit_still_parses_normally(): void
    {
        $request = new ServerRequest(
            'POST',
            '/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '3',
            ],
            'a=1',
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['a' => '1'], $captured->getParsedBody());
    }
}
