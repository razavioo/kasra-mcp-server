<?php

namespace App\Services\Hr\Kasra;

use RuntimeException;

class KasraException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
