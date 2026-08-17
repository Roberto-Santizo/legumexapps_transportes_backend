<?php

namespace App\Errors;

class ServiceUnavailableError extends ApiException
{
    public function getStatusCode(): int
    {
        return 503;
    }
}
