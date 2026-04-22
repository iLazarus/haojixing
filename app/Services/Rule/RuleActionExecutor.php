<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppMember;
use App\Models\TgGroup;
use App\Models\TgUser;
use App\Models\AppRule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RuleActionExecutor
{
    public function __construct(
        private readonly HttpKernel $httpKernel,
    ) {
    }

    public function buildAction(AppRule $rule, array $matches, array $context): array
    {
        // 1) 先把规则配置标准化：模板、API 地址、载荷都统一插值展开。
        $map = $this->decodeDataMap($rule->data_map);

        $replyTemplate = is_string($map['reply_template'] ?? null) ? $map['reply_template'] : null;
        $apiMethod = $this->normalizeMethod($rule->method ?? null);
        $apiTemplate = is_string($rule->api) && trim($rule->api) !== '' ? trim($rule->api) : null;

        // 2) 扫描 rule 中实际使用到的占位符，按需动态补齐上下文。
        $apiPayloadTemplate = $map['api_payload'] ?? [];
        $placeholderKeys = array_values(array_unique(array_merge(
            $replyTemplate !== null ? $this->extractPlaceholderKeysFromTemplate($replyTemplate) : [],
            $apiTemplate !== null ? $this->extractPlaceholderKeysFromTemplate($apiTemplate) : [],
            $this->collectPlaceholderKeysFromMixed($apiPayloadTemplate),
        )));
        $runtimeContext = $this->enrichContextByPlaceholders($context, $placeholderKeys);

        $replyText = $replyTemplate !== null ? $this->interpolate($replyTemplate, $matches, $runtimeContext) : null;
        $apiUrl = $apiTemplate !== null ? trim($this->interpolate($apiTemplate, $matches, $runtimeContext)) : null;
        if ($apiUrl === '') {
            $apiUrl = null;
        }
        $apiPayload = $this->interpolateMixed($apiPayloadTemplate, $matches, $runtimeContext);

        // 2) 根据“是否有 API、是否有回复文本”决定执行模式。
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
            'api_method' => $apiMethod,
            'api' => $apiUrl,
            'api_payload' => $apiPayload,
            'context' => $runtimeContext,
            'reply_template' => $replyTemplate,
            'reply_text' => $replyText,
        ];
    }

    public function renderReplyText(array $action, array $matches, array $context, ?array $apiResult): ?string
    {
        // 某些失败场景由上游标记 suppress_reply，这里直接不回消息。
        if (is_array($apiResult) && ($apiResult['suppress_reply'] ?? false) === true) {
            return null;
        }

        $replyTemplate = is_string($action['reply_template'] ?? null) ? $action['reply_template'] : null;
        if ($replyTemplate === null) {
            return is_string($action['reply_text'] ?? null) ? (string) $action['reply_text'] : null;
        }

        // API 调用失败时，不再沿用成功模板，避免误导用户。
        if (($action['mode'] ?? null) === 'api_and_reply' && is_array($apiResult) && ($apiResult['ok'] ?? true) !== true) {
            $errorMessage = '请求失败';
            if (is_array($apiResult['body'] ?? null) && is_string($apiResult['body']['message'] ?? null)) {
                $errorMessage = (string) $apiResult['body']['message'];
            } elseif (is_string($apiResult['error'] ?? null) && trim((string) $apiResult['error']) !== '') {
                $errorMessage = (string) $apiResult['error'];
            }

            return sprintf('记账失败，原因=%s', $errorMessage);
        }

        // API 结果注入运行时上下文，供模板使用 {{api_result.*}} / {{result.*}}。
        $runtimeContext = $context;
        if ($apiResult !== null) {
            $runtimeContext['api_result'] = $apiResult;
            if (is_array($apiResult['body'] ?? null)) {
                $runtimeContext['result'] = $apiResult['body'];
            }
        }

        return $this->interpolate($replyTemplate, $matches, $runtimeContext);
    }

    public function executeApiIfNeeded(array $action, bool $executeApi): ?array
    {
        // 仅在命中 API 相关模式且允许执行时才真正发起调用。
        if (!$executeApi || !in_array($action['mode'], ['api_call', 'api_and_reply'], true)) {
            return null;
        }

        $rawUrl = (string) ($action['api'] ?? '');
        if ($rawUrl === '') {
            return null;
        }

        $method = $this->normalizeMethod($action['api_method'] ?? null);
        $payload = is_array($action['api_payload'] ?? null) ? $action['api_payload'] : [];
        $context = is_array($action['context'] ?? null) ? $action['context'] : [];
        $lastError = null;

        // 先做业务语义预处理（权限、字段补全、金额/币种规范化）。
        $preparedPayload = $this->preparePayloadForApi($method, $rawUrl, $payload, $context);
        if ($preparedPayload['ok'] === false) {
            return [
                'method' => $method,
                'url' => $rawUrl,
                'status' => 422,
                'ok' => false,
                'body' => ['message' => (string) ($preparedPayload['error'] ?? 'invalid payload')],
                'raw' => (string) json_encode(['message' => (string) ($preparedPayload['error'] ?? 'invalid payload')], JSON_UNESCAPED_UNICODE),
                'transport' => 'internal',
                'suppress_reply' => (bool) ($preparedPayload['suppress_reply'] ?? false),
            ];
        }
        $payload = is_array($preparedPayload['payload'] ?? null) ? $preparedPayload['payload'] : $payload;

        // 优先走内部直调，避免本机回环 HTTP 带来的额外网络开销。
        $internalResult = $this->tryExecuteInternalApi($method, $rawUrl, $payload);
        if ($internalResult !== null) {
            return $internalResult;
        }

        // 内部直调未命中时，按候选地址顺序进行 HTTP 回退重试。
        foreach ($this->buildApiCallCandidates($rawUrl) as $url) {
            try {
                Log::channel('stderr')->debug('rule_api_call_start', [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload,
                    'transport' => 'external',
                ]);

                $request = Http::connectTimeout(1)->timeout(2)->asJson();
                $response = match ($method) {
                    'PATCH' => $request->patch($url, $payload),
                    'GET' => $request->get($url, $payload),
                    'DELETE' => $request->delete($url, $payload),
                    default => $request->post($url, $payload),
                };

                Log::channel('stderr')->debug('rule_api_call_done', [
                    'method' => $method,
                    'url' => $url,
                    'status' => $response->status(),
                    'ok' => $response->successful(),
                    'transport' => 'external',
                ]);

                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => $response->status(),
                    'ok' => $response->successful(),
                    'body' => $response->json(),
                    'raw' => $response->body(),
                ];
            } catch (Throwable $e) {
                $lastError = $e;
                Log::channel('stderr')->warning('rule_api_call_failed', [
                    'method' => $method,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'method' => $method,
            'url' => $rawUrl,
            'status' => null,
            'ok' => false,
            'body' => null,
            'raw' => null,
            'error' => $lastError?->getMessage() ?? 'api request failed',
        ];
    }

    private function tryExecuteInternalApi(string $method, string $url, array $payload): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        // 仅处理“本地可识别主机”的内部路由，其他主机交给外部 HTTP。
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && !$this->isLocalLikeHost($host)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $query = isset($parts['query']) ? (string) $parts['query'] : '';
        $forwardUrl = $path . ($query !== '' ? ('?' . $query) : '');

        // GET/DELETE 把 payload 作为 query 合并，POST/PATCH 保持 JSON body。
        if (in_array($method, ['GET', 'DELETE'], true) && $payload !== []) {
            $mergedQuery = $query;
            $payloadQuery = http_build_query($payload);
            if ($payloadQuery !== '') {
                $mergedQuery = $mergedQuery === '' ? $payloadQuery : ($mergedQuery . '&' . $payloadQuery);
                $forwardUrl = $path . '?' . $mergedQuery;
            }
        }

        try {
            Log::channel('stderr')->debug('rule_api_call_start', [
                'method' => $method,
                'url' => $url,
                'payload' => $payload,
                'transport' => 'internal',
            ]);

            $content = in_array($method, ['GET', 'DELETE'], true)
                ? null
                : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

            $request = Request::create(
                $forwardUrl,
                $method,
                [],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_HOST' => $host !== '' ? $host : 'localhost',
                ],
                $content
            );

            $response = $this->httpKernel->handle($request);
            try {
                $this->httpKernel->terminate($request, $response);
            } catch (Throwable) {
                // terminate 失败不影响主流程。
            }

            $rawBody = $response->getContent();
            $decodedBody = is_string($rawBody) ? json_decode($rawBody, true) : null;

            Log::channel('stderr')->debug('rule_api_call_done', [
                'method' => $method,
                'url' => $url,
                'status' => $response->getStatusCode(),
                'ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                'transport' => 'internal',
            ]);

            return [
                'method' => $method,
                'url' => $url,
                'status' => $response->getStatusCode(),
                'ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                'body' => is_array($decodedBody) ? $decodedBody : null,
                'raw' => is_string($rawBody) ? $rawBody : null,
                'transport' => 'internal',
            ];
        } catch (Throwable $e) {
            Log::channel('stderr')->warning('rule_api_internal_call_failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'method' => $method,
                'url' => $url,
                'status' => 500,
                'ok' => false,
                'body' => ['message' => 'internal api execution failed'],
                'raw' => '{"message":"internal api execution failed"}',
                'error' => $e->getMessage(),
                'transport' => 'internal',
            ];
        }
    }

    /**
    * @return array{ok: bool, payload?: array<string, mixed>, error?: string, suppress_reply?: bool}
     */
    private function preparePayloadForApi(string $method, string $url, array $payload, array $context = []): array
    {
        // 通用预处理：按字段命名约定做标准化，不绑定具体业务 API 路径。
        $next = $payload;
        $tgGid = $this->normalizeGidValue(
            $next['tg_gid']
            ?? $context['tg_gid']
            ?? $context['chat_id']
            ?? $this->resolveFromArrayPath($context, 'chat.id')
            ?? null
        );
        if ($tgGid !== null) {
            $next['tg_gid'] = $tgGid;
        }

        $uidKeys = [];
        foreach (array_keys($next) as $key) {
            if (!is_string($key)) {
                continue;
            }

            if ($key === 'tg_uid' || str_ends_with($key, '_tg_uid')) {
                $uidKeys[] = $key;
            }
        }

        if (!in_array('tg_uid', $uidKeys, true)) {
            $uidKeys[] = 'tg_uid';
        }
        if (!in_array('tg_belong_uid', $uidKeys, true)) {
            $uidKeys[] = 'tg_belong_uid';
        }

        foreach ($uidKeys as $key) {
            $raw = $next[$key] ?? null;
            if (!$this->hasMeaningfulValue($raw)) {
                $raw = match ($key) {
                    'trigger_tg_uid' => $next['tg_uid'] ?? $context['trigger_tg_uid'] ?? null,
                    'target_tg_uid' => $next['target_username'] ?? $context['target_username'] ?? $context['reply_to_tg_uid'] ?? null,
                    'tg_belong_uid' => $context['reply_to_tg_uid'] ?? $next['tg_uid'] ?? null,
                    'tg_uid' => $context['tg_uid'] ?? $context['sender_uid'] ?? $this->resolveFromArrayPath($context, 'from.id') ?? null,
                    default => null,
                };
            }

            if (!$this->hasMeaningfulValue($raw)) {
                continue;
            }

            $normalizedUid = $this->normalizeUidValue($raw, $tgGid);
            if ($normalizedUid === null || $normalizedUid <= 0) {
                return ['ok' => false, 'error' => sprintf('%s 无法解析', $key)];
            }

            $next[$key] = $normalizedUid;
        }

        if ($this->hasMeaningfulValue($next['amount'] ?? null) || $this->hasMeaningfulValue($next['amount_cent'] ?? null)) {
            $amountCent = $this->normalizeAmountCent($next['amount'] ?? ($next['amount_cent'] ?? null));
            if ($amountCent === null) {
                return ['ok' => false, 'error' => 'amount 无法解析'];
            }

            if (array_key_exists('amount', $next)) {
                $next['amount'] = $amountCent;
            }
            if (array_key_exists('amount_cent', $next)) {
                $next['amount_cent'] = $amountCent;
            }
        }

        if (array_key_exists('currency_type', $next) || $this->hasMeaningfulValue($context['message'] ?? null)) {
            $currencyType = $this->normalizeCurrencyTypeFromSources($next, $context);
            if ($currencyType === null) {
                return ['ok' => false, 'error' => 'currency_type 仅允许 R 或 U'];
            }
            $next['currency_type'] = $currencyType;
        }

        $resolvedTgUid = isset($next['tg_uid']) ? (int) $next['tg_uid'] : null;
        $resolvedBelongUid = isset($next['tg_belong_uid']) ? (int) $next['tg_belong_uid'] : null;
        $resolvedGid = isset($next['tg_gid']) ? (int) $next['tg_gid'] : null;

        if (array_key_exists('tg_nickname', $next)) {
            $next['tg_nickname'] = $this->firstNonEmptyString([
                $next['tg_nickname'] ?? null,
                $next['sender'] ?? null,
                $context['sender'] ?? null,
                $resolvedTgUid !== null ? $this->findUserDisplayNameByUid($resolvedTgUid) : null,
            ]);
        }

        if (array_key_exists('tg_belong_nickname', $next)) {
            $next['tg_belong_nickname'] = $this->firstNonEmptyString([
                $next['tg_belong_nickname'] ?? null,
                $context['reply_to_sender'] ?? null,
                $context['tg_belong_nickname'] ?? null,
                ($resolvedBelongUid !== null && $resolvedTgUid !== null && $resolvedBelongUid === $resolvedTgUid) ? ($next['tg_nickname'] ?? null) : null,
                $resolvedBelongUid !== null ? $this->findUserDisplayNameByUid($resolvedBelongUid) : null,
            ]);
        }

        if (array_key_exists('tg_g_name', $next)) {
            $next['tg_g_name'] = $this->firstNonEmptyString([
                $next['tg_g_name'] ?? null,
                $next['chat_title'] ?? null,
                $context['chat_title'] ?? null,
                $resolvedGid !== null ? $this->findGroupNameByGid($resolvedGid) : null,
            ]);
        }

        return ['ok' => true, 'payload' => $next];
    }

    /**
     * @param array<int, string> $placeholderKeys
     * @return array<string, mixed>
     */
    private function enrichContextByPlaceholders(array $context, array $placeholderKeys): array
    {
        $next = $context;

        foreach ($placeholderKeys as $key) {
            if ($key === '' || $this->isPlaceholderResolved($next, $key)) {
                continue;
            }

            $inferred = $this->inferContextValueByPlaceholder($key, $next);
            if ($inferred === null) {
                continue;
            }

            $next = $this->setContextPathValue($next, $key, $inferred);
        }

        return $next;
    }

    /**
     * @return array<int, string>
     */
    private function collectPlaceholderKeysFromMixed(mixed $value): array
    {
        if (is_string($value)) {
            return $this->extractPlaceholderKeysFromTemplate($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $item) {
            $keys = array_merge($keys, $this->collectPlaceholderKeysFromMixed($item));
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function extractPlaceholderKeysFromTemplate(string $template): array
    {
        if ($template === '') {
            return [];
        }

        if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches) !== 1 || !is_array($matches[1] ?? null)) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '' || ctype_digit($key)) {
                continue;
            }
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    private function isPlaceholderResolved(array $context, string $key): bool
    {
        if (array_key_exists($key, $context)) {
            return true;
        }

        if (!str_contains($key, '.')) {
            return false;
        }

        return $this->resolveFromArrayPath($context, $key) !== null;
    }

    private function inferContextValueByPlaceholder(string $placeholderKey, array $context): mixed
    {
        $leafKey = $placeholderKey;
        if (str_contains($placeholderKey, '.')) {
            $segments = explode('.', $placeholderKey);
            $leafKey = (string) end($segments);
        }

        $tgGid = $this->normalizeGidValue(
            $context['tg_gid']
            ?? $context['chat_id']
            ?? $this->resolveFromArrayPath($context, 'chat.id')
            ?? null
        );

        return match ($leafKey) {
            'tg_gid' => $tgGid,
            'tg_uid' => $this->normalizeUidValue(
                $context['tg_uid']
                ?? $context['sender_uid']
                ?? $context['trigger_tg_uid']
                ?? $this->resolveFromArrayPath($context, 'from.id')
                ?? null,
                $tgGid,
            ),
            'trigger_tg_uid' => $this->normalizeUidValue(
                $context['trigger_tg_uid']
                ?? $context['tg_uid']
                ?? $context['sender_uid']
                ?? null,
                $tgGid,
            ),
            'target_tg_uid' => $this->normalizeUidValue(
                $context['target_tg_uid']
                ?? $context['target_username']
                ?? $context['reply_to_tg_uid']
                ?? null,
                $tgGid,
            ),
            'tg_belong_uid' => $this->normalizeUidValue(
                $context['tg_belong_uid']
                ?? $context['reply_to_tg_uid']
                ?? $context['target_tg_uid']
                ?? $context['tg_uid']
                ?? null,
                $tgGid,
            ),
            'currency_type' => $this->normalizeCurrencyTypeFromSources([], $context),
            'tg_nickname' => $this->firstNonEmptyString([
                $context['tg_nickname'] ?? null,
                $context['sender'] ?? null,
                isset($context['tg_uid']) ? $this->findUserDisplayNameByUid((int) $context['tg_uid']) : null,
            ]),
            'tg_belong_nickname' => $this->firstNonEmptyString([
                $context['tg_belong_nickname'] ?? null,
                $context['reply_to_sender'] ?? null,
                isset($context['reply_to_tg_uid']) ? $this->findUserDisplayNameByUid((int) $context['reply_to_tg_uid']) : null,
            ]),
            'tg_g_name' => $this->firstNonEmptyString([
                $context['tg_g_name'] ?? null,
                $context['chat_title'] ?? null,
                $tgGid !== null ? $this->findGroupNameByGid($tgGid) : null,
            ]),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function setContextPathValue(array $context, string $path, mixed $value): array
    {
        if (!str_contains($path, '.')) {
            $context[$path] = $value;
            return $context;
        }

        $segments = explode('.', $path);
        $last = array_pop($segments);
        if (!is_string($last) || $last === '') {
            return $context;
        }

        $cursor = &$context;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
        return $context;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    private function isLedgerOperatorMember(int $tgGid, int $tgUid): bool
    {
        if ($tgGid <= 0 || $tgUid <= 0) {
            return false;
        }

        return AppMember::query()
            ->where('tg_gid', $tgGid)
            ->where('tg_uid', $tgUid)
            ->where('is_active', true)
            ->where('role', AppMember::ROLE_OPERATOR)
            ->exists();
    }

    private function normalizeAmountCent(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        return (int) round(((float) $text) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function normalizeCurrencyTypeFromSources(array $payload, array $context): ?string
    {
        $candidates = [
            $payload['currency_type'] ?? null,
            $this->extractCurrencySuffix((string) ($payload['amount'] ?? '')),
            $this->extractCurrencySuffix((string) ($context['message'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $currency = strtoupper(trim($candidate));
            if ($currency === '') {
                continue;
            }

            if (in_array($currency, ['R', 'U'], true)) {
                return $currency;
            }

            return null;
        }

        return 'R';
    }

    private function extractCurrencySuffix(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/([RUru])\s*$/', $text, $matches) !== 1) {
            return null;
        }

        return strtoupper((string) ($matches[1] ?? ''));
    }

    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $value = trim($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function findUserDisplayNameByUid(int $tgUid): string
    {
        if ($tgUid <= 0) {
            return '';
        }

        $user = TgUser::query()
            ->select(['tg_nickname', 'tg_username'])
            ->where('tg_uid', $tgUid)
            ->first();

        if (!$user instanceof TgUser) {
            return '';
        }

        $nickname = trim((string) ($user->tg_nickname ?? ''));
        if ($nickname !== '') {
            return $nickname;
        }

        return trim((string) ($user->tg_username ?? ''));
    }

    private function findGroupNameByGid(int $tgGid): string
    {
        if ($tgGid <= 0) {
            return '';
        }

        $group = TgGroup::query()
            ->select(['tg_g_name'])
            ->where('tg_gid', $tgGid)
            ->first();

        return trim((string) ($group?->tg_g_name ?? ''));
    }

    private function normalizeGidValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    private function normalizeUidValue(mixed $value, ?int $tgGid = null): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $text) === 1) {
            return (int) $text;
        }

        $username = ltrim($text, '@');
        if ($username === '') {
            return null;
        }

        $user = $this->resolveUserByUsername($username, $tgGid);

        return $user?->tg_uid !== null ? (int) $user->tg_uid : null;
    }

    private function resolveUserByUsername(string $username, ?int $tgGid = null): ?TgUser
    {
        $normalized = trim($username);
        if ($normalized === '') {
            return null;
        }

        $query = TgUser::query()
            ->select(['tg_user.tg_uid'])
            ->whereRaw('LOWER(tg_user.tg_username) = ?', [strtolower($normalized)]);

        $user = $query->first();
        return $user instanceof TgUser ? $user : null;
    }

    /**
     * @return array<int, string>
     */
    private function buildApiCallCandidates(string $url): array
    {
        $candidates = [$url];
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $candidates;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!$this->isLocalLikeHost($host)) {
            return $candidates;
        }

        // 对本地地址构造多套可达候选，适配容器内/宿主机不同网络视角。
        $baseUrls = [];
        $configuredBase = trim((string) config('services.rule.internal_base_url', ''));
        if ($configuredBase !== '') {
            $baseUrls[] = $configuredBase;
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl !== '') {
            $baseUrls[] = $appUrl;
        }

        $baseUrls[] = 'http://nginx';
        $baseUrls[] = 'http://host.containers.internal:9001';

        $podmanGateway = trim((string) env('PODMAN_HOST_GATEWAY_IP', ''));
        if ($podmanGateway !== '') {
            $baseUrls[] = sprintf('http://%s:9001', $podmanGateway);
        }

        foreach ($baseUrls as $baseUrl) {
            $rewritten = $this->rewriteUrlBase($url, $baseUrl);
            if ($rewritten !== null && !in_array($rewritten, $candidates, true)) {
                $candidates[] = $rewritten;
            }
        }

        return $candidates;
    }

    private function isLocalLikeHost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', 'nginx'], true);
    }

    private function rewriteUrlBase(string $url, string $baseUrl): ?string
    {
        $target = parse_url($url);
        $base = parse_url($baseUrl);
        if (!is_array($target) || !is_array($base)) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? '');
        $host = (string) ($base['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $path = (string) ($target['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($target['query']) ? '?' . $target['query'] : '';
        $fragment = isset($target['fragment']) ? '#' . $target['fragment'] : '';

        return sprintf('%s://%s%s%s%s%s', $scheme, $host, $port, $path, $query, $fragment);
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
        // 模板变量解析优先级：context 直接键 > context 点路径 > 正则捕获组。
        return (string) preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function (array $m) use ($matches, $context): string {
            $key = trim((string) ($m[1] ?? ''));

            if (array_key_exists($key, $context)) {
                return (string) $context[$key];
            }

            $resolved = $this->resolveFromArrayPath($context, $key);
            if ($resolved !== null) {
                return is_scalar($resolved) || $resolved === null
                    ? (string) ($resolved ?? '')
                    : (string) json_encode($resolved, JSON_UNESCAPED_UNICODE);
            }

            if (ctype_digit($key)) {
                $idx = (int) $key;
                return (string) ($matches[$idx] ?? '');
            }

            return (string) ($matches[$key] ?? '');
        }, $template);
    }

    private function resolveFromArrayPath(array $data, string $path): mixed
    {
        if ($path === '' || !str_contains($path, '.')) {
            return null;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function normalizeMethod(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'POST';
        }

        $method = strtoupper(trim($value));
        return in_array($method, ['PATCH', 'POST', 'GET', 'DELETE'], true) ? $method : 'POST';
    }
}
