<?php

namespace Datalumo\Wp\Api;

use Exception;

class ApiException extends Exception
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
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
