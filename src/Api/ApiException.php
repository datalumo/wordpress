<?php

namespace Datalumo\Wp\Api;

use Exception;

class ApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isAuthentication(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    public function isQuota(): bool
    {
        return $this->status === 402;
    }
}
