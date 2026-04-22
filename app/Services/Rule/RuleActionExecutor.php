<?php

declare(strict_types=1);

namespace App\Services\Rule;

use App\Models\AppMember;
use App\Models\TgGroup;
use App\Models\TgUser;
use App\Services\Group\GroupSyncService;
use App\Services\Group\GroupService;
use App\Services\Ledger\LedgerService;
use App\Services\Member\MemberService;
use App\Models\AppRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RuleActionExecutor
{
    public function __construct(
        private readonly GroupService $groupService,
        private readonly LedgerService $ledgerService,
        private readonly GroupSyncService $groupSyncService,
        private readonly MemberService $memberService,
    ) {
    }

    public function buildAction(AppRule $rule, array $matches, array $context): array
    {
        $map = $this->decodeDataMap($rule->data_map);

        $replyTemplate = is_string($map['reply_template'] ?? null) ? $map['reply_template'] : null;
        $replyText = $replyTemplate !== null ? $this->interpolate($replyTemplate, $matches, $context) : null;

        $apiMethod = $this->normalizeMethod($rule->method ?? null);
        $apiTemplate = is_string($rule->api) && trim($rule->api) !== '' ? trim($rule->api) : null;
        $apiUrl = $apiTemplate !== null ? trim($this->interpolate($apiTemplate, $matches, $context)) : null;
        if ($apiUrl === '') {
            $apiUrl = null;
        }
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
            'api_method' => $apiMethod,
            'api' => $apiUrl,
            'api_payload' => $apiPayload,
            'context' => $context,
            'reply_template' => $replyTemplate,
            'reply_text' => $replyText,
        ];
    }

    public function renderReplyText(array $action, array $matches, array $context, ?array $apiResult): ?string
    {
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

        $internalResult = $this->tryExecuteInternalApi($method, $rawUrl, $payload);
        if ($internalResult !== null) {
            return $internalResult;
        }

        foreach ($this->buildApiCallCandidates($rawUrl) as $url) {
            try {
                $request = Http::connectTimeout(1)->timeout(2)->asJson();
                $response = match ($method) {
                    'PATCH' => $request->patch($url, $payload),
                    'GET' => $request->get($url, $payload),
                    'DELETE' => $request->delete($url, $payload),
                    default => $request->post($url, $payload),
                };

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

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && !$this->isLocalLikeHost($host)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($method === 'PATCH' && preg_match('#^/api/v1/groups/(-?\d+)$#', $path, $matches) === 1) {
            try {
                $tgGid = (int) $matches[1];
                $updated = $this->groupService->updateByTgGid($tgGid, $payload);

                if ($updated === null) {
                    return [
                        'method' => $method,
                        'url' => $url,
                        'status' => 404,
                        'ok' => false,
                        'body' => ['message' => 'group not found'],
                        'raw' => '{"message":"group not found"}',
                        'transport' => 'internal',
                    ];
                }

                $body = $updated->toArray();
                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => 200,
                    'ok' => true,
                    'body' => $body,
                    'raw' => (string) json_encode($body, JSON_UNESCAPED_UNICODE),
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

        if ($method === 'POST' && preg_match('#^/api/v1/ledgers$#', $path) === 1) {
            try {
                $created = $this->ledgerService->create($payload);
                $body = $created->toArray();

                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => 201,
                    'ok' => true,
                    'body' => $body,
                    'raw' => (string) json_encode($body, JSON_UNESCAPED_UNICODE),
                    'transport' => 'internal',
                ];
            } catch (InvalidArgumentException $e) {
                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => 422,
                    'ok' => false,
                    'body' => ['message' => $e->getMessage()],
                    'raw' => (string) json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
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

        if ($method === 'POST' && preg_match('#^/api/v1/groups/(-?\d+)/sync$#', $path, $matches) === 1) {
            try {
                $tgGid = (int) $matches[1];
                $result = $this->groupSyncService->refresh(
                    $tgGid,
                    isset($payload['trigger_tg_uid']) ? (int) $payload['trigger_tg_uid'] : null,
                    (string) ($payload['trigger_nickname'] ?? ''),
                    [],
                    isset($payload['fallback_group_name']) ? (string) $payload['fallback_group_name'] : null,
                );

                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => 200,
                    'ok' => true,
                    'body' => $result,
                    'raw' => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
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
                ];
            }
        }

        if ($method === 'POST' && preg_match('#^/api/v1/groups/(-?\d+)/members/set-operator$#', $path, $matches) === 1) {
            try {
                $tgGid = $this->normalizeGidValue($matches[1]);
                $triggerTgUid = $this->normalizeUidValue($payload['trigger_tg_uid'] ?? null, $tgGid);
                $targetTgUid = $this->normalizeUidValue($payload['target_tg_uid'] ?? null, $tgGid);

                if ($tgGid === null || $tgGid <= 0 || $triggerTgUid === null || $triggerTgUid <= 0 || $targetTgUid === null || $targetTgUid <= 0) {
                    return [
                        'method' => $method,
                        'url' => $url,
                        'status' => 422,
                        'ok' => false,
                        'body' => ['message' => '参数不完整，缺少 tg_gid/trigger_tg_uid/target_tg_uid'],
                        'raw' => '{"message":"参数不完整，缺少 tg_gid/trigger_tg_uid/target_tg_uid"}',
                        'transport' => 'internal',
                    ];
                }

                if (!$this->isLedgerOperatorMember($tgGid, $triggerTgUid)) {
                    return [
                        'method' => $method,
                        'url' => $url,
                        'status' => 403,
                        'ok' => false,
                        'body' => ['message' => '仅 operator 可以设置操作员'],
                        'raw' => '{"message":"仅 operator 可以设置操作员"}',
                        'transport' => 'internal',
                    ];
                }

                $member = $this->memberService->updateOne($tgGid, $targetTgUid, [
                    'role' => AppMember::ROLE_OPERATOR,
                ]);

                if ($member === null) {
                    return [
                        'method' => $method,
                        'url' => $url,
                        'status' => 404,
                        'ok' => false,
                        'body' => ['message' => 'member not found'],
                        'raw' => '{"message":"member not found"}',
                        'transport' => 'internal',
                    ];
                }

                $body = $member->toArray();
                return [
                    'method' => $method,
                    'url' => $url,
                    'status' => 200,
                    'ok' => true,
                    'body' => $body,
                    'raw' => (string) json_encode($body, JSON_UNESCAPED_UNICODE),
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

        return null;
    }

    /**
    * @return array{ok: bool, payload?: array<string, mixed>, error?: string, suppress_reply?: bool}
     */
    private function preparePayloadForApi(string $method, string $url, array $payload, array $context = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['ok' => true, 'payload' => $payload];
        }

        $path = (string) ($parts['path'] ?? '');
        if ($method === 'POST' && preg_match('#^/api/v1/groups/(-?\d+)/members/set-operator$#', $path, $matches) === 1) {
            $tgGid = $this->normalizeGidValue($matches[1]);
            if ($tgGid === null || $tgGid <= 0) {
                return ['ok' => false, 'error' => 'tg_gid 缺失或无效'];
            }

            $triggerUid = $this->normalizeUidValue($payload['trigger_tg_uid'] ?? ($payload['tg_uid'] ?? null), $tgGid);
            if ($triggerUid === null || $triggerUid <= 0) {
                return ['ok' => false, 'error' => 'trigger_tg_uid 缺失或无效'];
            }

            if (!$this->isLedgerOperatorMember($tgGid, $triggerUid)) {
                return ['ok' => false, 'error' => '仅 operator 可以设置操作员', 'suppress_reply' => true];
            }

            $targetUid = $this->normalizeUidValue($payload['target_tg_uid'] ?? ($payload['target_username'] ?? null), $tgGid);
            if ($targetUid === null || $targetUid <= 0) {
                return ['ok' => false, 'error' => '目标用户不存在或未同步，请先让 @username 发言后再试'];
            }

            return [
                'ok' => true,
                'payload' => [
                    'trigger_tg_uid' => $triggerUid,
                    'target_tg_uid' => $targetUid,
                ],
            ];
        }

        if ($method !== 'POST' || preg_match('#^/api/v1/ledgers$#', $path) !== 1) {
            return ['ok' => true, 'payload' => $payload];
        }

        $next = $payload;
        $next['tg_gid'] = $this->normalizeGidValue($next['tg_gid'] ?? null);
        if ($next['tg_gid'] === null || $next['tg_gid'] <= 0) {
            return ['ok' => false, 'error' => 'tg_gid 缺失或无效'];
        }

        $next['tg_uid'] = $this->normalizeUidValue($next['tg_uid'] ?? null, (int) $next['tg_gid']);
        if ($next['tg_uid'] === null || $next['tg_uid'] <= 0) {
            return ['ok' => false, 'error' => 'tg_uid 缺失或无效'];
        }

        if (!$this->isLedgerOperatorMember((int) $next['tg_gid'], (int) $next['tg_uid'])) {
            return ['ok' => false, 'error' => '仅 operator 角色可以记账', 'suppress_reply' => true];
        }

        $replyToUid = $this->normalizeUidValue($context['reply_to_tg_uid'] ?? null, (int) $next['tg_gid']);
        if ($replyToUid !== null && $replyToUid > 0) {
            $next['tg_belong_uid'] = $replyToUid;
        }

        $belongRaw = $next['tg_belong_uid'] ?? null;
        if ($belongRaw === null || (is_string($belongRaw) && trim($belongRaw) === '')) {
            $next['tg_belong_uid'] = $next['tg_uid'];
        } else {
            $resolvedBelongUid = $this->normalizeUidValue($belongRaw, (int) $next['tg_gid']);
            if ($resolvedBelongUid === null || $resolvedBelongUid <= 0) {
                return ['ok' => false, 'error' => 'tg_belong_uid 无法解析，请确认 @username 已同步到 tg_user'];
            }

            $next['tg_belong_uid'] = $resolvedBelongUid;
        }

        $amountCent = $this->normalizeAmountCent($next['amount_cent'] ?? ($next['amount'] ?? null));
        if ($amountCent === null) {
            return ['ok' => false, 'error' => 'amount 无法解析，请传入数字（单位：元）'];
        }
        $next['amount'] = $amountCent;

        $currencyType = $this->normalizeCurrencyTypeFromSources($next, $context);
        if ($currencyType === null) {
            return ['ok' => false, 'error' => 'currency_type 仅允许 R 或 U'];
        }
        $next['currency_type'] = $currencyType;

        $next['tg_nickname'] = $this->firstNonEmptyString([
            $next['tg_nickname'] ?? null,
            $next['sender'] ?? null,
            $context['sender'] ?? null,
            $this->findUserDisplayNameByUid((int) $next['tg_uid']),
        ]);

        $next['tg_belong_nickname'] = $this->firstNonEmptyString([
            $next['tg_belong_nickname'] ?? null,
            $context['reply_to_sender'] ?? null,
            $context['tg_belong_nickname'] ?? null,
            (int) $next['tg_belong_uid'] === (int) $next['tg_uid'] ? $next['tg_nickname'] : null,
            $this->findUserDisplayNameByUid((int) $next['tg_belong_uid']),
        ]);

        $next['tg_g_name'] = $this->firstNonEmptyString([
            $next['tg_g_name'] ?? null,
            $next['chat_title'] ?? null,
            $context['chat_title'] ?? null,
            $this->findGroupNameByGid((int) $next['tg_gid']),
        ]);

        return ['ok' => true, 'payload' => $next];
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
            return abs($value);
        }

        if (is_float($value)) {
            return abs((int) $value);
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

        return abs((int) $text);
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
