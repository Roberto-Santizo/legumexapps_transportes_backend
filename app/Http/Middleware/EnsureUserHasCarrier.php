<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Errors\ForbiddenError;
use App\Helpers\ResponseHandler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasCarrier
{
    /**
     * Handle an incoming request.
     *
     * Only carriers and pilots are ever linked to a company, so every other role
     * is exempt. Everyone else is resolved against the database, never against
     * the token claim, which may be up to one hour stale.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if ($user === null) {
            return ResponseHandler::error(new ForbiddenError('Debes estar vinculado a un transportista para acceder a este recurso'));
        }

        $exempt = ! in_array($user->role, [UserRole::Carrier, UserRole::Pilot], true);

        if (! $exempt && $user->currentCarrier() === null) {
            return ResponseHandler::error(new ForbiddenError('Debes estar vinculado a un transportista para acceder a este recurso'));
        }

        return $next($request);
    }
}
