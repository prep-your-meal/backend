<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Force Laravel to always render JSON since this is a pure headless API backend
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return true;
        });

        // Catch missing login route error and map it to 401 JSend
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if (str_contains($e->getMessage(), 'Route [login] not defined')) {
                return response()->json([
                    'status' => 'fail',
                    'data' => [
                        'message' => 'Unauthenticated.',
                    ],
                ], 401);
            }
        });

        // 1. JSend Compliant: Unauthenticated (401)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'status' => 'fail',
                'data' => [
                    'message' => 'Unauthenticated.',
                ],
            ], 401);
        });

        // 2. JSend Compliant: Validation Errors (422)
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'status' => 'fail',
                'data' => $e->errors(),
            ], 422);
        });

        // 3. JSend Compliant: Route Not Found / 404
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'status' => 'fail',
                'data' => [
                    'message' => 'The requested endpoint could not be found.',
                ],
            ], 404);
        });

    })->create();
