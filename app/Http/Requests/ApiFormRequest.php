<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'code' => 42200,
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
            'trace_id' => $this->header('X-Trace-Id', (string) Str::uuid()),
        ], 422));
    }
}
