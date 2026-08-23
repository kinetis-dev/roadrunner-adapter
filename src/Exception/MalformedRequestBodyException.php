<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Exception;

use RuntimeException;

/**
 * The client's request body could not be turned into a request —
 * {@see \Kinetis\RoadRunnerAdapter\RoadRunnerAdapter::handle()} answers
 * this with the same fixed 400 SuperglobalsBridge gives under
 * FrankenPHP/FPM. The message is logged and never returned: it can echo
 * a fragment of the input.
 */
final class MalformedRequestBodyException extends RuntimeException
{
    public static function unparseableMultipart(string $reason): self
    {
        return new self("The multipart/form-data body could not be parsed: {$reason}");
    }
}
