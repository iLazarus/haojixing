<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->renderable(function (InvalidArgumentException $e, Request $request): ?JsonResponse {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 40000,
                'message' => $e->getMessage(),
                'trace_id' => $this->traceId($request),
            ], 400);
        });

        $this->renderable(function (Throwable $e, Request $request): ?JsonResponse {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 50000,
                'message' => 'internal server error',
                'trace_id' => $this->traceId($request),
            ], 500);
        });
    }

    private function traceId(Request $request): string
    {
        $incoming = (string) $request->header('X-Trace-Id', '');
        return $incoming !== '' ? $incoming : (string) Str::uuid();
    }
}
