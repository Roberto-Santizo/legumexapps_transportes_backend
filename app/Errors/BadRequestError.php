<?php

namespace App\Errors;

class BadRequestError extends ApiException
{
    public function getStatusCode(): int
    {
        return 400;
    }
}
