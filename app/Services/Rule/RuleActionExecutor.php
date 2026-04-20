<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppRule;
use Illuminate\Support\Facades\Http;

class RuleActionExecutor
{
    public function buildAction(AppRule $rule, array $matches, array $context): array
    {
        $map = $this->decodeDataMap($rule->data_map);

        $replyTemplate = is_string($map['reply_template'] ?? null) ? $map['reply_template'] : null;
        $replyText = $replyTemplate !== null ? $this->interpolate($replyTemplate, $matches, $context) : null;

        $apiUrl = is_string($rule->api) && trim($rule->api) !== '' ? trim($rule->api) : null;
        $apiPayload = $this->interpolateMixed($map['api_payload'] ?? [], $matches, $context);

        $mode = 'noop';
        if ($apiUrl !== null && $replyText !== null) {
            $mode = 'api_and_reply';
        } elseif ($apiUrl !== null) {
            $mode = 'api_call';
        } elseif ($replyText !== null) {
            $mode = 'reply_text';
        }

        return [
            'mode' => $mode,
            'api' => $apiUrl,
            'api_payload' => $apiPayload,
            'reply_text' => $replyText,
        ];
    }

    public function executeApiIfNeeded(array $action, bool $executeApi): ?array
    {
        if (!$executeApi || !in_array($action['mode'], ['api_call', 'api_and_reply'], true)) {
            return null;
        }

        $url = (string) ($action['api'] ?? '');
        if ($url === '') {
            return null;
        }

        $payload = is_array($action['api_payload'] ?? null) ? $action['api_payload'] : [];

        $response = Http::connectTimeout(1)->timeout(2)->asJson()->post($url, $payload);

        return [
            'status' => $response->status(),
            'ok' => $response->successful(),
            'body' => $response->json(),
            'raw' => $response->body(),
        ];
    }

    private function decodeDataMap(?string $dataMap): array
    {
        if ($dataMap === null || trim($dataMap) === '') {
            return [];
        }

        $decoded = json_decode($dataMap, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function interpolateMixed(mixed $value, array $matches, array $context): mixed
    {
        if (is_string($value)) {
            return $this->interpolate($value, $matches, $context);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->interpolateMixed($v, $matches, $context);
            }
            return $result;
        }

        return $value;
    }

    private function interpolate(string $template, array $matches, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function (array $m) use ($matches, $context): string {
            $key = trim((string) ($m[1] ?? ''));

            if (array_key_exists($key, $context)) {
                return (string) $context[$key];
            }

            if (ctype_digit($key)) {
                $idx = (int) $key;
                return (string) ($matches[$idx] ?? '');
            }

            return (string) ($matches[$key] ?? '');
        }, $template);
    }
}
