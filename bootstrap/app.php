<?php

use App\Errors\UnauthorizedError;
use App\Helpers\ResponseHandler;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.auth' => Authenticate::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /** El middleware jwt.auth lanza antes de llegar al try/catch del controlador: se devuelve el mismo sobre que ResponseHandler. */
        $exceptions->render(function (UnauthorizedHttpException $th, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ResponseHandler::error(new UnauthorizedError('El token de sesión no es válido o ha expirado'));
        });
    })->create();
