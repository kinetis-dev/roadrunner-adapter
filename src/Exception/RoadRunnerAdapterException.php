<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Exception;

use RuntimeException;

final class RoadRunnerAdapterException extends RuntimeException
{
    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open a php://temp stream to parse a multipart body.');
    }

    public static function rawBodyNotEnabled(): self
    {
        return new self(
            'RoadRunner already parsed this form body itself before handing the '
            . 'request to PHP, which means http.raw_body: true is missing from the '
            . 'RoadRunner configuration. Set it in .rr.yaml — see the "Running under '
            . 'RoadRunner" section of docs/runtime-adapters.md.',
        );
    }
}
