<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidReportTransitionException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
