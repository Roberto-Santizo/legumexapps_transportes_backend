<?php

namespace App\Errors;

class ForbiddenError extends ApiException
{
    public function getStatusCode(): int
    {
        return 403;
    }
}
