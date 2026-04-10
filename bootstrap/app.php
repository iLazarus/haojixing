<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\InvalidArgumentException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 40000,
                'message' => $e->getMessage(),
                'trace_id' => $request->header('X-Trace-Id', (string) Str::uuid()),
            ], 400);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 50000,
                'message' => 'internal server error',
                'trace_id' => $request->header('X-Trace-Id', (string) Str::uuid()),
            ], 500);
        });
    })->create();
