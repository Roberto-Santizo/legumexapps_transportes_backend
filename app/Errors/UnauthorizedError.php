<?php

namespace App\Errors;

class UnauthorizedError extends ApiException
{
    public function getStatusCode(): int
    {
        return 401;
    }
}
