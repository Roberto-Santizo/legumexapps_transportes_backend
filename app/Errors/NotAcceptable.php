<?php

namespace App\Errors;

class NotAcceptable extends ApiException
{
    public function getStatusCode(): int
    {
        return 406;
    }
}
