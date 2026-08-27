<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class IdempotencyInProgressException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'The Idempotency-Key is already processing a request.')
    {
        parent::__construct($message);
    }
}
