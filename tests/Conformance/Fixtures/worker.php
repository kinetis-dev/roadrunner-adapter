<?php

declare(strict_types=1);

// The RoadRunner worker RoadRunnerDriver spawns `rr serve` against —
// the RoadRunner counterpart of kinetis/framework's own
// tests/Runtime/Conformance/Fixtures/index.php, reusing the identical
// protocol (an X-Conformance-Id/X-Conformance-Response header pair, an
// observed-request JSON file under KINETIS_CONFORMANCE_STATE_DIR) and
// even the identical handler body — RuntimeDetector::detect() already
// picks RoadRunnerAdapter here the same way it picks FrankenPhpAdapter/
// FpmAdapter under those environments, so nothing RoadRunner-specific
// needed writing at all.

require __DIR__ . '/../../../vendor/autoload.php';

use Kinetis\Runtime\RuntimeDetector;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\ResponseSpec;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

RuntimeDetector::detect()->run(static function (ServerRequestInterface $request): ResponseInterface {
    if ($request->getUri()->getPath() === '/__conformance/ready') {
        return new \Nyholm\Psr7\Response(204);
    }

    // Deliberately thrown before anything else runs, so this exercises
    // exactly what RoadRunnerAdapter::run()'s own catch (Throwable)
    // block does — never anything this fixture's own dispatch logic
    // wraps. The message names something a real client must never see
    // (RoadRunnerConformanceTest asserts on this directly): proving
    // that stays server-side is the actual point of the test this
    // fixture exists for.
    if ($request->getUri()->getPath() === '/__conformance/throw') {
        throw new RuntimeException('SENSITIVE_INTERNAL_DETAIL: SELECT * FROM secrets WHERE token = xyz');
    }

    $stateDir = getenv('KINETIS_CONFORMANCE_STATE_DIR');
    $id = $request->getHeaderLine('X-Conformance-Id');

    if (!is_string($stateDir) || $stateDir === '' || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
        throw new LogicException('conformance worker invoked without its state directory or dispatch id');
    }

    file_put_contents(
        $stateDir . '/' . $id . '.json',
        json_encode(ObservedRequest::fromServerRequest($request)->toArray(), JSON_THROW_ON_ERROR),
    );

    /** @var array<string, mixed> $spec */
    $spec = json_decode(
        (string) base64_decode($request->getHeaderLine('X-Conformance-Response'), strict: true),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // The one header this fixture adds itself, on top of whatever the
    // test's own ResponseSpec asked for — carries this worker's real
    // process id so a test can prove two dispatches were served by the
    // exact same persistent worker (RoadRunnerConformanceTest's
    // worker-survival test), not merely that both happened to succeed.
    return ResponseSpec::fromArray($spec)->toResponse()
        ->withHeader('X-Conformance-Worker-Pid', (string) getmypid());
});
