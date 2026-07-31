<?php

namespace App\Errors;

abstract class ApiException extends \Exception
{
    abstract public function getStatusCode(): int;
}
