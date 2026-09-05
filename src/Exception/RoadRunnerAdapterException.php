<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Exception;

use RuntimeException;

final class RoadRunnerAdapterException extends RuntimeException
{
    public static function rawBodyNotEnabled(): self
    {
        return new self(
            'RoadRunner already parsed this form body itself before handing the '
            . 'request to PHP, which means http.raw_body: true is missing from the '
            . 'RoadRunner configuration. Set it in .rr.yaml — see the "Running under '
            . 'RoadRunner" section of docs/runtime-adapters.md.',
        );
    }

    /**
     * The worker library did not stamp the request with the flag that
     * says whether RoadRunner parsed the body itself, so whether
     * `http.raw_body: true` is set cannot be determined from here.
     * Refused rather than assumed: assuming it set is exactly how a
     * pre-parsed body would go undetected and be re-parsed into wrong
     * fields.
     */
    public static function rawBodyUndetectable(): self
    {
        return new self(
            'This RoadRunner worker did not report whether it parsed the request '
            . 'body itself, so http.raw_body cannot be verified. kinetis/roadrunner-adapter '
            . 'requires a spiral/roadrunner-http version that sets the rr_parsed_body '
            . 'request attribute.',
        );
    }
}
