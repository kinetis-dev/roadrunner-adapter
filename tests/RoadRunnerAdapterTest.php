<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Responses\ErrorResponse;
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
use Psr\Http\Message\UploadedFileInterface;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;

/**
 * What only `RoadRunnerAdapter::handle()` itself does — capability
 * detection, form-body parsing, header folding, the forwarded scheme,
 * the streaming refusal — proven directly against a fabricated
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
        self::assertTrue((new RoadRunnerAdapter(self::limits(), self::proxies()))->isPersistent());
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
            }, self::limits(), self::proxies());

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
            }, self::limits(), self::proxies());

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

    // --- Bodies ---------------------------------------------------------

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

        $request = self::request('POST', '/avatars', ['Content-Type' => "multipart/form-data; boundary={$boundary}"], $body);
        $captured = $this->capture($request);

        self::assertSame(['name' => 'Alon'], $captured->getParsedBody());
        self::assertArrayHasKey('avatar', $captured->getUploadedFiles());
        self::assertSame('a.png', $captured->getUploadedFiles()['avatar']->getClientFilename());
        self::assertSame('image/png', $captured->getUploadedFiles()['avatar']->getClientMediaType());

        // getContents(), not (string), which seeks to zero itself and so
        // reads the same bytes whether or not the body was left at its
        // end: this is what a handler reading the body after the form
        // was parsed actually gets.
        self::assertSame($body, $captured->getBody()->getContents(), 'parsing the form must leave the raw bytes readable');
    }

    public function test_parses_a_url_encoded_body(): void
    {
        $captured = $this->capture(self::request(
            'POST',
            '/search',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'q=kinetis&page=2',
        ));

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

        $captured = $this->capture(self::request(
            'POST',
            '/avatars',
            ['Content-Type' => "Multipart/Form-Data; boundary={$boundary}"],
            $body,
        ));

        self::assertSame(['name' => 'Alon'], $captured->getParsedBody());
    }

    /**
     * A longer media type that merely begins with a form one is a
     * different media type: the body stays raw bytes, and none of the
     * form-body machinery (the size ceilings, either parser) runs for it.
     */
    public function test_a_content_type_that_only_looks_like_a_form_type_is_left_untouched(): void
    {
        $captured = $this->capture(self::request(
            'POST',
            '/search',
            ['Content-Type' => 'application/x-www-form-urlencodedevil'],
            'q=kinetis',
        ));

        self::assertNull($captured->getParsedBody());
        self::assertSame('q=kinetis', (string) $captured->getBody());
    }

    public function test_a_non_form_content_type_is_left_untouched(): void
    {
        $captured = $this->capture(self::request('POST', '/', ['Content-Type' => 'application/json'], '{"a":1}'));

        self::assertNull($captured->getParsedBody());
        self::assertSame('{"a":1}', (string) $captured->getBody());
    }

    public function test_a_multipart_body_with_no_usable_boundary_is_a_clean_400_and_the_handler_never_runs(): void
    {
        $response = $this->handleWithoutHandler(self::request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=----XYZ'],
            'not a real multipart body at all',
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    public function test_a_multipart_content_type_with_no_boundary_at_all_is_a_clean_400(): void
    {
        $response = $this->handleWithoutHandler(self::request('POST', '/', ['Content-Type' => 'multipart/form-data'], 'anything'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    /**
     * A part naming a charset PHP has no converter for makes
     * `mb_convert_encoding()` throw a `ValueError` from inside the
     * parser — client-chosen input, per part, and so the same fixed
     * `400` any other unreadable body gets rather than an uncaught
     * failure the worker reports as its own.
     */
    public function test_a_part_declaring_an_unknown_charset_is_a_clean_400(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"note\"\r\n"
            . "Content-Type: text/plain; charset=definitely-not-a-charset\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . "hello\r\n"
            . "--{$boundary}--\r\n";

        $response = $this->handleWithoutHandler(self::request(
            'POST',
            '/',
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    // --- The multipart contract -----------------------------------------

    /**
     * The shared conformance suite runs these against a real `rr serve`
     * (see {@see \Kinetis\RoadRunnerAdapter\Tests\RoadRunnerConformanceTest}),
     * which needs a fetched `rr` binary. They run here too, straight
     * against `handle()`, so the one thing that differs between this
     * adapter and core — `riverline/multipart-parser` in place of the
     * framework's own — is held to the same contract in every checkout.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function bodiesOutsideTheMultipartContract(): iterable
    {
        yield 'a padded delimiter line' => ["--B \r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n--B--\r\n"];
        yield 'a boundary after a bare newline' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\n--B\r\nContent-Disposition: form-data; name=\"b\"\r\n\r\nw\r\n--B--\r\n"];
        yield 'a decoding transfer encoding' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Transfer-Encoding: base64\r\n\r\naGk=\r\n--B--\r\n"];
        yield 'an extended parameter' => ["--B\r\nContent-Disposition: form-data; name*=not-a-charset''a\r\n\r\nv\r\n--B--\r\n"];
        yield 'an encoded word' => ["--B\r\nContent-Disposition: form-data; name=\"=?utf-8?B?YWJj?=\"\r\n\r\nv\r\n--B--\r\n"];
        yield 'a nested multipart part' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Type: multipart/mixed; boundary=I\r\n\r\n--I\r\nContent-Disposition: form-data; name=\"n\"\r\n\r\nx\r\n--I--\r\n\r\n--B--\r\n"];
        yield 'a repeated Content-Disposition' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Disposition: form-data; name=\"b\"\r\n\r\nv\r\n--B--\r\n"];
    }

    #[DataProvider('bodiesOutsideTheMultipartContract')]
    public function test_a_body_outside_the_multipart_contract_is_a_clean_400(string $body): void
    {
        $response = $this->handleWithoutHandler(self::request('POST', '/', ['Content-Type' => 'multipart/form-data; boundary=B'], $body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    /**
     * The other half of the same contract: a line whose boundary token
     * is only a prefix is payload, and the part keeps it byte for byte
     * rather than ending there.
     */
    public function test_a_part_body_that_only_begins_like_a_delimiter_stays_payload(): void
    {
        $request = $this->capture(self::request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=B'],
            "--B\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nfirst\r\n--Btra\r\nsecond\r\n--B--\r\n",
        ));

        self::assertSame(['note' => "first\r\n--Btra\r\nsecond"], $request->getParsedBody());
    }

    /**
     * A file part that declared no `Content-Type` has no client media
     * type. `riverline/multipart-parser`'s own `getMimeType()` answers
     * `application/octet-stream` for exactly this case, which would
     * report a media type the client never sent and disagree with every
     * other runtime.
     */
    public function test_a_file_part_declaring_no_content_type_reports_no_media_type(): void
    {
        $request = $this->capture(self::request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=B'],
            "--B\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n\r\nbytes\r\n--B--\r\n",
        ));

        $files = $request->getUploadedFiles();
        self::assertArrayHasKey('doc', $files);
        self::assertInstanceOf(UploadedFileInterface::class, $files['doc']);
        self::assertNull($files['doc']->getClientMediaType());
        self::assertSame('a.txt', $files['doc']->getClientFilename());
    }

    // --- The form-complexity contract -----------------------------------

    /**
     * The declared length is the cheap check: a body honestly labeled as
     * oversized is refused before anything copies or parses it.
     */
    public function test_a_form_body_declaring_a_content_length_over_the_limit_is_a_clean_413_and_the_handler_never_runs(): void
    {
        $response = $this->handleWithoutHandler(self::request(
            'POST',
            '/',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '3000000'],
            'a=1',
        ));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            ['error' => BodyTooLargeException::exceeds(FormLimits::DEFAULT_MAX_BODY_BYTES)->getMessage()],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * And the check that actually bounds the request: the bytes in hand.
     * A client that understates its length — or declares none — passes
     * the declared check trivially, so a limit resting on that alone
     * would not be a limit.
     */
    public function test_a_form_body_larger_than_the_limit_is_refused_on_its_real_size_whatever_it_declared(): void
    {
        $response = $this->handleWithoutHandler(
            self::request(
                'POST',
                '/',
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '3'],
                // csrf_token last, past the edge: a byte cap that
                // truncated instead of refusing would hand on a form
                // missing exactly it.
                'pad=' . str_repeat('x', 200) . '&csrf_token=t',
            ),
            self::limits(64),
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            ['error' => BodyTooLargeException::exceeds(64)->getMessage()],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * The ceiling is the application's own configured value, carried in
     * the FormLimits this adapter was constructed with — the same
     * instance MaxBodySizeMiddleware enforces inside the Kernel, so a
     * form body and a raw one cannot meet two different numbers.
     */
    public function test_the_form_body_limit_is_the_configured_one(): void
    {
        $response = $this->handleWithoutHandler(
            self::request(
                'POST',
                '/',
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '11'],
                'a=1',
            ),
            self::limits(10),
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            ['error' => BodyTooLargeException::exceeds(10)->getMessage()],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * A `Content-Length` that isn't a pure digit string — this one has a
     * numeric prefix large enough to overflow past the limit if it were
     * cast anyway — is treated as no declaration at all, and the body's
     * real size is what bounds it. This one is three bytes.
     */
    public function test_a_non_digit_content_length_leaves_the_real_size_to_bound_the_request(): void
    {
        $captured = $this->capture(self::request(
            'POST',
            '/',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '9999999999abc'],
            'a=1',
        ));

        self::assertSame(['a' => '1'], $captured->getParsedBody());
    }

    /**
     * A declared length exactly *equal* to the limit must pass — only
     * strictly *over* it is too large. Distinguishes the `>` comparison
     * from a mutated `>=`.
     */
    public function test_a_content_length_exactly_at_the_limit_is_not_rejected(): void
    {
        $handlerRan = false;
        $captured = null;

        RoadRunnerAdapter::handle(
            self::request(
                'POST',
                '/',
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '10'],
                'a=1',
            ),
            static function (ServerRequestInterface $seen) use (&$captured, &$handlerRan): Response {
                $handlerRan = true;
                $captured = $seen;

                return new Response(200);
            },
            self::limits(10),
            self::proxies(),
        );

        self::assertTrue($handlerRan);
        self::assertSame(['a' => '1'], $captured?->getParsedBody());
    }

    /**
     * The one ceiling only an adapter running its own parser can see — a
     * SAPI never exposes a part's headers — held to the same `413` the
     * shared suite holds every other ceiling to.
     */
    public function test_a_multipart_part_with_more_headers_than_the_contract_allows_is_refused(): void
    {
        $boundary = 'B';
        $part = "--{$boundary}\r\nContent-Disposition: form-data; name=\"csrf_token\"\r\n";

        for ($i = 0; $i <= FormLimits::MAX_PART_HEADERS; $i++) {
            $part .= "X-Pad-{$i}: v\r\n";
        }

        $response = $this->handleWithoutHandler(self::request(
            'POST',
            '/',
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            $part . "\r\nt\r\n--{$boundary}--\r\n",
        ));

        self::assertSame(413, $response->getStatusCode());
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
        self::assertSame(['Malformed request body: unreadable-forwarded-header'], $logged);
    }

    /**
     * What the adapter actually writes to the log for a parse failure,
     * captured rather than reasoned about. `error_log()` is the last
     * place a parser's own text could still surface — the response is
     * already fixed — so the sentinel is checked against the real log
     * line, with a body built to make a parser produce the most
     * quotable message it can.
     */
    public function test_a_parse_failure_logs_a_fixed_category_and_never_the_parsers_own_text(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"secret-field-name\"\r\n"
            . "Content-Type: text/plain; charset=definitely-not-a-charset\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . "secret-body-fragment\r\n"
            . "--{$boundary}--\r\n";

        [$response, $logged] = $this->handleCapturingTheLog(self::request(
            'POST',
            '/',
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['Malformed request body: undecodable-part'], $logged, 'the category is what an operator triages on');

        foreach (['definitely-not-a-charset', 'secret-field-name', 'secret-body-fragment', 'mb_convert_encoding'] as $sentinel) {
            self::assertStringNotContainsString($sentinel, implode(PHP_EOL, $logged), 'the parser names the input that failed; the log must not');
        }
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
            self::limits(),
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

    private static function limits(int $maxBodyBytes = FormLimits::DEFAULT_MAX_BODY_BYTES): FormLimits
    {
        return new FormLimits($maxBodyBytes);
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
        }, self::limits(), self::proxies());

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

    private function handleWithoutHandler(ServerRequestInterface $request, ?FormLimits $limits = null): ResponseInterface
    {
        $handlerRan = false;

        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        }, $limits ?? self::limits(), self::proxies());

        self::assertFalse($handlerRan, 'the handler must not run for a body the adapter refused');

        return $response;
    }
}
