<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ApiResponder
{
    protected function success(Request $request, mixed $data = null, int $status = 200, string $message = 'ok'): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
            'trace_id' => $this->traceId($request),
        ], $status);
    }

    protected function error(
        Request $request,
        string $message,
        int $status,
        int $code,
        mixed $errors = null
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'trace_id' => $this->traceId($request),
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    private function traceId(Request $request): string
    {
        $incoming = (string) $request->header('X-Trace-Id', '');
        return $incoming !== '' ? $incoming : (string) Str::uuid();
    }
}
