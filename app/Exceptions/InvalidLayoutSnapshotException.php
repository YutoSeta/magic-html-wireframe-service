<?php

namespace App\Exceptions;

use RuntimeException;

final class InvalidLayoutSnapshotException extends RuntimeException
{
    /** @param list<string> $problems */
    public static function fromProblems(array $problems): self
    {
        return new self('The Layout Snapshot is invalid: '.implode(' / ', array_slice($problems, 0, 20)));
    }
}
