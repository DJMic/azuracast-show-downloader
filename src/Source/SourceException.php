<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

use RuntimeException;

final class SourceException extends RuntimeException
{
    /** True when the file simply is not there (yet): the job may retry later. */
    public bool $notFound = false;

    public static function notFound(string $message): self
    {
        $e = new self($message);
        $e->notFound = true;

        return $e;
    }
}
