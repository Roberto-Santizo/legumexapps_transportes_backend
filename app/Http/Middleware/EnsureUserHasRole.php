<?php

namespace App\Http\Middleware;

use App\Errors\ForbiddenError;
use App\Helpers\ResponseHandler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * Coarse grained filter: only the given roles may reach the route. The
     * error is returned, not thrown, because the controller try/catch never
     * sees an exception raised inside a middleware.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles  Role values allowed on this route.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role->value, $roles, true)) {
            return ResponseHandler::error(new ForbiddenError('No tienes permisos para acceder a este recurso'));
        }

        return $next($request);
    }
}
