<?php

namespace App\Errors;

class NotFoundError extends ApiException
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
