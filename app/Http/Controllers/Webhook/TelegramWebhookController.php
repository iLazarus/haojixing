<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\Group\GroupSyncService;
use App\Services\Rule\RuleMatchingService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    private ?int $currentInboxId = null;

    public function __construct(
        private readonly RuleMatchingService $ruleMatchingService,
        private readonly GroupSyncService $groupSyncService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $this->currentInboxId = null;

        Log::channel('stderr')->info('telegram_webhook_handle_enter', [
            'content_type' => (string) $request->header('Content-Type', ''),
            'content_length' => (int) $request->server('CONTENT_LENGTH', 0),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'raw_preview' => mb_substr((string) $request->getContent(), 0, 500),
        ]);

        try {
            $payload = $this->readPayload($request);
            if ($payload === null || $payload === []) {
                return $this->ignored('invalid_payload');
            }

            $updateId = $this->toIntOrNull($payload['update_id'] ?? null);
            $this->currentInboxId = $this->recordInboxReceived($payload, $updateId);
            if ($this->isDuplicateUpdate($updateId)) {
                return $this->ignored('duplicate_update', [
                    'update_id' => $updateId,
                ]);
            }

            $createdGroupContext = $this->syncGroupWhenBotAdded($payload);
            $createdGroupId = $createdGroupContext['tg_gid'] ?? null;
            $memberChangedContext = $this->extractMemberChangedContext($payload);
            $memberChangedGroupId = $memberChangedContext['tg_gid'] ?? null;
            $memberChangedCreatedContext = null;

            if ($memberChangedContext !== null && $memberChangedGroupId !== $createdGroupId) {
                $ensureResult = $this->ensureGroupExists($memberChangedContext, 'member_changed');
                if ($ensureResult === 'created') {
                    $memberChangedCreatedContext = $memberChangedContext;
                }
            }

            if ($createdGroupId !== null) {
                $this->syncUsersWhenGroupMemberChanged($createdGroupId, $payload, $createdGroupContext);
            }

            if ($memberChangedGroupId !== null && $memberChangedGroupId !== $createdGroupId) {
                $this->syncUsersWhenGroupMemberChanged($memberChangedGroupId, $payload, $memberChangedContext);
            }

            if ($createdGroupContext !== null && (string) ($createdGroupContext['ensure_result'] ?? '') === 'created') {
                $this->sendGroupRefreshNotice($createdGroupContext);
            } elseif ($memberChangedCreatedContext !== null) {
                $this->sendGroupRefreshNotice($memberChangedCreatedContext);
            }

            [$message, $updateType] = $this->extractMessage($payload);
            Log::channel('stderr')->info('telegram_webhook_received', [
                'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                'update_type' => $updateType,
                'update_keys' => array_keys($payload),
            ]);

        if ($message === null) {
            return $this->ignored('unsupported_update', [
                'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                'update_type' => $updateType,
            ]);
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
        if ($chat === null) {
            return $this->ignored('missing_chat');
        }

        $chatType = is_string($chat['type'] ?? null) ? strtolower(trim((string) $chat['type'])) : '';
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return $this->ignored('not_group_chat', [
                'chat_type' => $chatType,
                'chat_id' => $this->toIntOrNull($chat['id'] ?? null),
            ]);
        }

        $tgMsgId = $this->toIntOrNull($message['message_id'] ?? null);
        if ($tgMsgId === null || $tgMsgId <= 0) {
            return $this->ignored('invalid_message_id', [
                'message_id_raw' => $message['message_id'] ?? null,
            ]);
        }

        $text = $this->pickMessageText($message);
        if ($text === null || trim($text) === '') {
            return $this->ignored('empty_text', [
                'chat_id' => $this->toIntOrNull($chat['id'] ?? null),
                'tg_msg_id' => $tgMsgId,
            ]);
        }

        $rawGroupId = $this->toIntOrNull($chat['id'] ?? null);
        if ($rawGroupId === null) {
            return $this->ignored('invalid_group_id', [
                'chat_id_raw' => $chat['id'] ?? null,
            ]);
        }

        $replyToMessage = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;
        $replyToFrom = is_array($replyToMessage['from'] ?? null) ? $replyToMessage['from'] : null;

        $context = [
            'sender' => is_array($message['from'] ?? null) ? (string) ($message['from']['username'] ?? $message['from']['first_name'] ?? '') : '',
            'tg_uid' => $this->toIntOrNull($message['from']['id'] ?? null),
            'reply_to_tg_uid' => $this->toIntOrNull($replyToFrom['id'] ?? null),
            'reply_to_sender' => is_array($replyToFrom) ? (string) ($replyToFrom['username'] ?? $replyToFrom['first_name'] ?? '') : '',
            'chat_type' => $chatType,
            'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
            'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
        ];

            $this->logIncomingGroupMessage($rawGroupId, $tgMsgId, $text, $context);

            if ($this->isRefreshCommand($text)) {
                $senderUid = $this->toIntOrNull($message['from']['id'] ?? null);
                if (!$this->groupSyncService->isGroupOwner($rawGroupId, $senderUid)) {
                    $this->sendTelegramTextMessage($rawGroupId, '仅群主可以执行刷新', 'manual_refresh_forbidden');
                    $this->markInboxResult('已处理', '刷新被拒绝', null, [
                        '处理场景' => '手动刷新',
                        '处理说明' => '发送人不是群主，系统拒绝执行刷新',
                    ]);

                    return response()->json([
                        'ok' => true,
                        'ignored' => 'refresh_forbidden',
                    ]);
                }

                $seedUsers = [];
                $this->collectVisibleUsersFromPayload($seedUsers, $payload);
                $syncResult = $this->groupSyncService->refresh(
                    $rawGroupId,
                    $senderUid,
                    (string) ($context['sender'] ?? ''),
                    $seedUsers,
                    (string) ($context['chat_title'] ?? ''),
                );

                Log::channel('stderr')->info('telegram_manual_refresh_finished', [
                    'tg_gid' => $rawGroupId,
                    'tg_uid' => $senderUid,
                    'sync_result' => $syncResult,
                ]);

                $this->sendTelegramTextMessage($rawGroupId, '已刷新群信息、用户信息和成员信息', 'manual_refresh_done');
                $this->markInboxResult('已处理', '手动刷新完成', null, [
                    '处理场景' => '手动刷新',
                    '处理说明' => '已完成群与成员同步',
                    '刷新结果' => $syncResult,
                ]);

                return response()->json([
                    'ok' => true,
                    'matched' => 0,
                    'replied' => true,
                    'data' => [
                        'sync' => $syncResult,
                    ],
                ]);
            }

        $groupIds = $this->resolveGroupIds($rawGroupId);
        $result = null;

        foreach ($groupIds as $tgGid) {
            $result = $this->ruleMatchingService->match(
                $tgGid,
                $tgMsgId,
                $text,
                true,
                $context
            );

            if (((int) ($result['hit_count'] ?? 0)) > 0) {
                Log::channel('stderr')->info('telegram_rule_match_group_hit', [
                    'raw_group_id' => $rawGroupId,
                    'matched_group_id' => $tgGid,
                    'tg_msg_id' => $tgMsgId,
                    'hit_count' => (int) ($result['hit_count'] ?? 0),
                ]);
                break;
            }
        }

        $replyText = $this->extractReplyTextFromMatchResult($result);
        Log::channel('stderr')->info('telegram_rule_match_result', [
            'tg_gid' => $rawGroupId,
            'tg_msg_id' => $tgMsgId,
            'text_preview' => mb_substr($text, 0, 120),
            'candidate_group_ids' => $groupIds,
            'hit_count' => (int) ($result['hit_count'] ?? 0),
            'reply_ready' => $replyText !== null && trim($replyText) !== '',
            'reply_preview' => is_string($replyText) ? mb_substr($replyText, 0, 120) : null,
        ]);

        if ($replyText !== null && trim($replyText) !== '') {
            $this->sendTelegramTextMessage($rawGroupId, $replyText, 'rule_reply');
            $this->markInboxResult('已处理', '规则命中并已回复', null, [
                '处理场景' => '规则匹配',
                '命中规则数' => (int) ($result['hit_count'] ?? 0),
                '是否发送回复' => '是',
            ]);
        } else {
            Log::channel('stderr')->info('telegram_rule_reply_skipped', [
                'tg_gid' => $rawGroupId,
                'tg_msg_id' => $tgMsgId,
                'reason' => ((int) ($result['hit_count'] ?? 0)) > 0 ? 'hit_without_reply_text' : 'no_rule_hit',
            ]);

            $this->markInboxResult(
                '已处理',
                ((int) ($result['hit_count'] ?? 0)) > 0 ? '规则命中但无需回复' : '未命中任何规则',
                null,
                [
                    '处理场景' => '规则匹配',
                    '命中规则数' => (int) ($result['hit_count'] ?? 0),
                    '是否发送回复' => '否',
                ]
            );
        }

            return response()->json([
                'ok' => true,
                'matched' => (int) ($result['hit_count'] ?? 0),
                'replied' => $replyText !== null && trim($replyText) !== '',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            $this->markInboxResult('处理失败', '内部异常', $e->getMessage(), [
                '处理场景' => 'Webhook处理',
                '处理说明' => '执行过程中发生未捕获异常',
            ]);

            Log::channel('stderr')->error('telegram_webhook_handle_exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_preview' => mb_substr($e->getTraceAsString(), 0, 1500),
                'content_type' => (string) $request->header('Content-Type', ''),
                'content_length' => (int) $request->server('CONTENT_LENGTH', 0),
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => 'internal_error',
            ]);
        }
    }

    private function syncGroupWhenBotAdded(array $payload): ?array
    {
        $context = $this->extractBotJoinContext($payload);
        if ($context === null) {
            return null;
        }

        $result = $this->ensureGroupExists($context, 'bot_added');

        if ($result === 'failed') {
            return null;
        }

        $context['ensure_result'] = $result;

        return $context;
    }

    private function sendGroupRefreshNotice(array $context): void
    {
        $tgGid = $this->toIntOrNull($context['tg_gid'] ?? null);
        if ($tgGid === null || $tgGid === 0) {
            return;
        }

        $groupName = trim((string) ($context['chat_title'] ?? ''));
        if ($groupName === '') {
            $groupName = (string) $tgGid;
        }

        $text = "机器人已经刷新了'{$groupName}' 的成员和群配置信息";
        $this->sendTelegramTextMessage($tgGid, $text, 'group_refresh_notice');
    }

    private function extractReplyTextFromMatchResult(?array $result): ?string
    {
        if (!is_array($result)) {
            return null;
        }

        $hits = $result['hits'] ?? null;
        if (!is_array($hits) || $hits === []) {
            return null;
        }

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $action = is_array($hit['action'] ?? null) ? $hit['action'] : null;
            if ($action === null) {
                continue;
            }

            $replyText = $action['reply_text'] ?? null;
            if (is_string($replyText) && trim($replyText) !== '') {
                return $replyText;
            }
        }

        return null;
    }

    private function sendTelegramTextMessage(int $chatId, string $text, string $scene): bool
    {
        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            Log::channel('stderr')->warning('telegram_bot_token_missing', [
                'scene' => $scene,
                'chat_id' => $chatId,
            ]);

            return false;
        }

        $resolveIp = trim((string) config('services.telegram.api_resolve_ip', ''));

        $curlOptions = [];
        if (defined('CURLOPT_DNS_CACHE_TIMEOUT')) {
            $curlOptions[CURLOPT_DNS_CACHE_TIMEOUT] = 300;
        }
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if ($resolveIp !== '' && defined('CURLOPT_RESOLVE')) {
            $curlOptions[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$resolveIp}"];
        }

        try {
            // DNS 抖动时优先复用 DNS 缓存，并允许可选固定解析 IP 绕过系统 DNS。
            $response = Http::withOptions(['curl' => $curlOptions])
                ->connectTimeout(3)
                ->timeout(5)
                ->retry(2, 120)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
        } catch (Throwable $e) {
            Log::channel('stderr')->warning('telegram_send_message_exception', [
                'scene' => $scene,
                'chat_id' => $chatId,
                'resolve_ip' => $resolveIp !== '' ? $resolveIp : null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (!$response->ok()) {
            Log::channel('stderr')->warning('telegram_send_message_failed', [
                'scene' => $scene,
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return false;
        }

        Log::channel('stderr')->info('telegram_send_message_success', [
            'scene' => $scene,
            'chat_id' => $chatId,
            'text_preview' => mb_substr($text, 0, 120),
        ]);

        return true;
    }

    private function ensureGroupExists(array $context, string $scene): string
    {
        $tgGid = $this->toIntOrNull($context['tg_gid'] ?? null);
        if ($tgGid === null || $tgGid === 0) {
            Log::channel('stderr')->warning('group_sync_invalid_tg_gid', [
                'scene' => $scene,
                'tg_gid' => $context['tg_gid'] ?? null,
                'update_id' => $context['update_id'] ?? null,
            ]);

            return 'failed';
        }

        $checkResponse = $this->callInternalJsonApi('GET', '/api/v1/groups/' . $context['tg_gid']);
        if ($checkResponse['status'] === 200) {
            return 'exists';
        }

        if ($checkResponse['status'] !== 404) {
            Log::channel('stderr')->warning('group_sync_check_failed', [
                'scene' => $scene,
                'tg_gid' => $context['tg_gid'],
                'status' => $checkResponse['status'],
                'body' => mb_substr($checkResponse['body'], 0, 500),
            ]);

            return 'failed';
        }

        $tgOid = $this->toIntOrNull($context['tg_oid'] ?? null);
        if ($tgOid === null || $tgOid <= 0) {
            Log::channel('stderr')->warning('group_sync_skipped_missing_tg_oid', [
                'scene' => $scene,
                'tg_gid' => $context['tg_gid'],
                'tg_oid' => $context['tg_oid'] ?? null,
                'update_id' => $context['update_id'] ?? null,
            ]);

            return 'failed';
        }

        $createResponse = $this->callInternalJsonApi('POST', '/api/v1/groups', [
            'tg_gid' => $context['tg_gid'],
            'tg_oid' => $tgOid,
        ]);

        if ($createResponse['status'] < 200 || $createResponse['status'] >= 300) {
            Log::channel('stderr')->warning('group_sync_create_failed', [
                'scene' => $scene,
                'tg_gid' => $context['tg_gid'],
                'tg_oid' => $tgOid,
                'status' => $createResponse['status'],
                'body' => mb_substr($createResponse['body'], 0, 500),
            ]);

            return 'failed';
        }

        Log::channel('stderr')->info('group_auto_created_from_webhook', [
            'scene' => $scene,
            'tg_gid' => $context['tg_gid'],
            'tg_oid' => $tgOid,
            'chat_title' => $context['chat_title'],
            'chat_type' => $context['chat_type'],
            'update_id' => $context['update_id'],
        ]);

        return 'created';
    }

    private function syncUsersWhenGroupMemberChanged(int $tgGid, array $payload, ?array $context = null): void
    {
        $users = [];
        $this->collectVisibleUsersFromPayload($users, $payload);

        $triggerUid = $this->toIntOrNull($context['tg_oid'] ?? null);
        if ($triggerUid === null) {
            $triggerUid = $this->toIntOrNull($payload['message']['from']['id'] ?? null)
                ?? $this->toIntOrNull($payload['chat_member']['from']['id'] ?? null)
                ?? $this->toIntOrNull($payload['my_chat_member']['from']['id'] ?? null);
        }

        $triggerNickname = trim((string) ($context['sender'] ?? ''));
        if ($triggerNickname === '') {
            $triggerNickname = trim((string) ($payload['message']['from']['first_name'] ?? ''));
        }

        $fallbackGroupName = trim((string) ($context['chat_title'] ?? ''));
        if ($fallbackGroupName === '') {
            $fallbackGroupName = trim((string) ($payload['message']['chat']['title'] ?? ''));
        }

        $syncResult = $this->groupSyncService->refresh(
            $tgGid,
            $triggerUid,
            $triggerNickname,
            $users,
            $fallbackGroupName,
        );

        Log::channel('stderr')->info('group_user_member_sync_finished', [
            'tg_gid' => $tgGid,
            'candidate_count' => count($users),
            'sync_result' => $syncResult,
        ]);
    }

    private function isRefreshCommand(string $text): bool
    {
        return trim($text) === '刷新';
    }

    private function collectVisibleUsersFromPayload(array &$users, array $payload): void
    {
        $fromUser = is_array($payload['from'] ?? null) ? $payload['from'] : null;
        if ($fromUser !== null) {
            $this->pushTelegramUser($users, $fromUser);
        }

        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message !== null) {
            $messageFrom = is_array($message['from'] ?? null) ? $message['from'] : null;
            if ($messageFrom !== null) {
                $this->pushTelegramUser($users, $messageFrom);
            }

            $newMembers = $message['new_chat_members'] ?? null;
            if (is_array($newMembers)) {
                foreach ($newMembers as $member) {
                    if (!is_array($member)) {
                        continue;
                    }

                    $this->pushTelegramUser($users, $member);
                }
            }

            $leftMember = is_array($message['left_chat_member'] ?? null) ? $message['left_chat_member'] : null;
            if ($leftMember !== null) {
                $this->pushTelegramUser($users, $leftMember);
            }
        }

        $chatMember = is_array($payload['chat_member'] ?? null) ? $payload['chat_member'] : null;
        if ($chatMember !== null) {
            $chatMemberFrom = is_array($chatMember['from'] ?? null) ? $chatMember['from'] : null;
            if ($chatMemberFrom !== null) {
                $this->pushTelegramUser($users, $chatMemberFrom);
            }

            $oldChatMemberUser = is_array($chatMember['old_chat_member']['user'] ?? null) ? $chatMember['old_chat_member']['user'] : null;
            if ($oldChatMemberUser !== null) {
                $this->pushTelegramUser($users, $oldChatMemberUser);
            }

            $newChatMemberUser = is_array($chatMember['new_chat_member']['user'] ?? null) ? $chatMember['new_chat_member']['user'] : null;
            if ($newChatMemberUser !== null) {
                $this->pushTelegramUser($users, $newChatMemberUser);
            }
        }

        $myChatMember = is_array($payload['my_chat_member'] ?? null) ? $payload['my_chat_member'] : null;
        if ($myChatMember !== null) {
            $myChatMemberFrom = is_array($myChatMember['from'] ?? null) ? $myChatMember['from'] : null;
            if ($myChatMemberFrom !== null) {
                $this->pushTelegramUser($users, $myChatMemberFrom);
            }

            $oldMyChatMemberUser = is_array($myChatMember['old_chat_member']['user'] ?? null) ? $myChatMember['old_chat_member']['user'] : null;
            if ($oldMyChatMemberUser !== null) {
                $this->pushTelegramUser($users, $oldMyChatMemberUser);
            }

            $newMyChatMemberUser = is_array($myChatMember['new_chat_member']['user'] ?? null) ? $myChatMember['new_chat_member']['user'] : null;
            if ($newMyChatMemberUser !== null) {
                $this->pushTelegramUser($users, $newMyChatMemberUser);
            }
        }
    }

    private function upsertTelegramUser(array $telegramUser): string
    {
        $tgUid = $this->toIntOrNull($telegramUser['id'] ?? null);
        if ($tgUid === null || $tgUid <= 0) {
            return 'skipped';
        }

        $payload = [
            'tg_uid' => $tgUid,
            'tg_username' => $this->normalizeTelegramUsername($telegramUser),
            'tg_nickname' => $this->normalizeTelegramNickname($telegramUser),
        ];

        $check = $this->callInternalJsonApi('GET', '/api/v1/users/' . $tgUid);
        if ($check['status'] === 404) {
            $create = $this->callInternalJsonApi('POST', '/api/v1/users', $payload);

            if ($create['status'] < 200 || $create['status'] >= 300) {
                Log::channel('stderr')->warning('group_user_sync_create_failed', [
                    'tg_uid' => $tgUid,
                    'status' => $create['status'],
                    'body' => mb_substr($create['body'], 0, 500),
                ]);
            }

            return ($create['status'] >= 200 && $create['status'] < 300) ? 'created' : 'failed';
        }

        if ($check['status'] !== 200) {
            Log::channel('stderr')->warning('group_user_sync_check_failed', [
                'tg_uid' => $tgUid,
                'status' => $check['status'],
                'body' => mb_substr($check['body'], 0, 500),
            ]);

            return 'failed';
        }

        $current = $this->extractApiData($check['body']);
        $currentUsername = is_string($current['tg_username'] ?? null) ? (string) $current['tg_username'] : null;
        $currentNickname = is_string($current['tg_nickname'] ?? null) ? (string) $current['tg_nickname'] : null;

        $patchPayload = [];
        if ($currentUsername !== $payload['tg_username']) {
            $patchPayload['tg_username'] = $payload['tg_username'];
        }
        if ($currentNickname !== $payload['tg_nickname']) {
            $patchPayload['tg_nickname'] = $payload['tg_nickname'];
        }
        if ($patchPayload === []) {
            return 'skipped';
        }

        $update = $this->callInternalJsonApi('PATCH', '/api/v1/users/' . $tgUid, $patchPayload);

        if ($update['status'] < 200 || $update['status'] >= 300) {
            Log::channel('stderr')->warning('group_user_sync_update_failed', [
                'tg_uid' => $tgUid,
                'status' => $update['status'],
                'body' => mb_substr($update['body'], 0, 500),
            ]);
        }

        return ($update['status'] >= 200 && $update['status'] < 300) ? 'updated' : 'failed';
    }

    private function normalizeTelegramUsername(array $telegramUser): ?string
    {
        $username = is_string($telegramUser['username'] ?? null) ? trim((string) $telegramUser['username']) : '';

        return $username === '' ? null : mb_substr($username, 0, 64);
    }

    private function normalizeTelegramNickname(array $telegramUser): ?string
    {
        $nickname = trim((string) ($telegramUser['first_name'] ?? ''));
        $lastName = trim((string) ($telegramUser['last_name'] ?? ''));
        if ($nickname !== '' && $lastName !== '') {
            $nickname .= ' ' . $lastName;
        } elseif ($nickname === '' && $lastName !== '') {
            $nickname = $lastName;
        }

        return $nickname === '' ? null : mb_substr($nickname, 0, 128);
    }

    private function pushTelegramUser(array &$users, array $telegramUser): void
    {
        $tgUid = $this->toIntOrNull($telegramUser['id'] ?? null);
        if ($tgUid === null || $tgUid <= 0) {
            return;
        }

        $users[$tgUid] = $telegramUser;
    }

    private function extractMemberChangedContext(array $payload): ?array
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message !== null) {
            $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
            $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
            if (in_array($chatType, ['group', 'supergroup'], true)) {
                $hasJoin = is_array($message['new_chat_members'] ?? null) && $message['new_chat_members'] !== [];
                $hasLeave = is_array($message['left_chat_member'] ?? null);
                if ($hasJoin || $hasLeave) {
                    return [
                        'tg_gid' => $this->toIntOrNull($chat['id'] ?? null),
                        'tg_oid' => $this->toIntOrNull($message['from']['id'] ?? null),
                        'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
                        'chat_type' => $chatType,
                        'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                    ];
                }
            }
        }

        $chatMember = is_array($payload['chat_member'] ?? null) ? $payload['chat_member'] : null;
        if ($chatMember !== null) {
            $chat = is_array($chatMember['chat'] ?? null) ? $chatMember['chat'] : null;
            $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
            if (in_array($chatType, ['group', 'supergroup'], true)) {
                $oldStatus = is_string($chatMember['old_chat_member']['status'] ?? null) ? (string) $chatMember['old_chat_member']['status'] : '';
                $newStatus = is_string($chatMember['new_chat_member']['status'] ?? null) ? (string) $chatMember['new_chat_member']['status'] : '';
                if ($oldStatus !== $newStatus) {
                    return [
                        'tg_gid' => $this->toIntOrNull($chat['id'] ?? null),
                        'tg_oid' => $this->toIntOrNull($chatMember['from']['id'] ?? null),
                        'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
                        'chat_type' => $chatType,
                        'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                    ];
                }
            }
        }

        $myChatMember = is_array($payload['my_chat_member'] ?? null) ? $payload['my_chat_member'] : null;
        if ($myChatMember !== null) {
            $chat = is_array($myChatMember['chat'] ?? null) ? $myChatMember['chat'] : null;
            $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
            if (in_array($chatType, ['group', 'supergroup'], true)) {
                $oldStatus = is_string($myChatMember['old_chat_member']['status'] ?? null) ? (string) $myChatMember['old_chat_member']['status'] : '';
                $newStatus = is_string($myChatMember['new_chat_member']['status'] ?? null) ? (string) $myChatMember['new_chat_member']['status'] : '';
                if ($oldStatus !== $newStatus) {
                    return [
                        'tg_gid' => $this->toIntOrNull($chat['id'] ?? null),
                        'tg_oid' => $this->toIntOrNull($myChatMember['from']['id'] ?? null),
                        'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
                        'chat_type' => $chatType,
                        'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                    ];
                }
            }
        }

        return null;
    }

    private function extractBotJoinContext(array $payload): ?array
    {
        $fromMyChatMember = $this->extractBotJoinContextFromMyChatMember($payload);
        if ($fromMyChatMember !== null) {
            return $fromMyChatMember;
        }

        return $this->extractBotJoinContextFromNewChatMembers($payload);
    }

    private function extractBotJoinContextFromMyChatMember(array $payload): ?array
    {
        $myChatMember = is_array($payload['my_chat_member'] ?? null) ? $payload['my_chat_member'] : null;
        if ($myChatMember === null) {
            return null;
        }

        $newMember = is_array($myChatMember['new_chat_member'] ?? null) ? $myChatMember['new_chat_member'] : null;
        $oldMember = is_array($myChatMember['old_chat_member'] ?? null) ? $myChatMember['old_chat_member'] : null;
        if ($newMember === null || $oldMember === null) {
            return null;
        }

        $newStatus = is_string($newMember['status'] ?? null) ? (string) $newMember['status'] : '';
        $oldStatus = is_string($oldMember['status'] ?? null) ? (string) $oldMember['status'] : '';
        if (!in_array($newStatus, ['member', 'administrator'], true) || in_array($oldStatus, ['member', 'administrator'], true)) {
            return null;
        }

        $chat = is_array($myChatMember['chat'] ?? null) ? $myChatMember['chat'] : null;
        if ($chat === null) {
            return null;
        }

        $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return null;
        }

        $chatId = $this->toIntOrNull($chat['id'] ?? null);
        if ($chatId === null) {
            return null;
        }

        $operatorId = $this->toIntOrNull($payload['from']['id'] ?? null);
        if ($operatorId === null || $operatorId <= 0) {
            return null;
        }

        return [
            'tg_gid' => $chatId,
            'tg_oid' => $operatorId,
            'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
            'chat_type' => $chatType,
            'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
        ];
    }

    private function extractBotJoinContextFromNewChatMembers(array $payload): ?array
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message === null) {
            return null;
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
        if ($chat === null) {
            return null;
        }

        $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return null;
        }

        $chatId = $this->toIntOrNull($chat['id'] ?? null);
        if ($chatId === null) {
            return null;
        }

        $newMembers = $message['new_chat_members'] ?? null;
        if (!is_array($newMembers) || $newMembers === []) {
            return null;
        }

        $botId = $this->resolveBotId();
        if ($botId === null) {
            Log::channel('stderr')->info('group_sync_skipped_missing_bot_id', [
                'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
            ]);

            return null;
        }

        $selfAdded = false;

        foreach ($newMembers as $member) {
            if (!is_array($member)) {
                continue;
            }

            $memberId = $this->toIntOrNull($member['id'] ?? null);
            $isBot = (bool) ($member['is_bot'] ?? false);
            if (!$isBot || $memberId === null) {
                continue;
            }

            if ($botId !== null && $memberId === $botId) {
                $selfAdded = true;
                break;
            }
        }

        if (!$selfAdded) {
            return null;
        }

        $operatorId = $this->toIntOrNull($message['from']['id'] ?? null);
        if ($operatorId === null || $operatorId <= 0) {
            return null;
        }

        return [
            'tg_gid' => $chatId,
            'tg_oid' => $operatorId,
            'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
            'chat_type' => $chatType,
            'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
        ];
    }

    private function resolveBotId(): ?int
    {
        $configuredBotId = $this->toIntOrNull(config('services.telegram.bot_id'));
        if ($configuredBotId !== null && $configuredBotId > 0) {
            return $configuredBotId;
        }

        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            return null;
        }

        return Cache::remember('telegram:bot:id', now()->addHours(6), function () use ($token): ?int {
            $response = Http::timeout(5)->acceptJson()->get("https://api.telegram.org/bot{$token}/getMe");
            if (!$response->ok()) {
                return null;
            }

            $botId = $this->toIntOrNull($response->json('result.id'));

            return $botId !== null && $botId > 0 ? $botId : null;
        });
    }

    private function callInternalJsonApi(string $method, string $uri, ?array $payload = null): array
    {
        $content = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $request = Request::create(
            $uri,
            strtoupper($method),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $content === false ? null : $content,
        );

        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $body = method_exists($response, 'getContent') ? (string) $response->getContent() : '';

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
        ];
    }

    private function extractApiData(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    private function extractChatId(array $payload): ?int
    {
        [$message] = $this->extractMessage($payload);
        if (!is_array($message)) {
            return null;
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
        if ($chat === null) {
            return null;
        }

        return $this->toIntOrNull($chat['id'] ?? null);
    }

    private function ignored(string $reason, array $context = []): JsonResponse
    {
        $status = $reason === 'duplicate_update' ? '重复更新' : '已忽略';
        $resultText = match ($reason) {
            'duplicate_update' => '更新重复（去重）',
            'invalid_payload' => '载荷无效',
            'unsupported_update' => '不支持的更新类型',
            'missing_chat' => '缺少聊天上下文',
            'not_group_chat' => '非群聊消息',
            'invalid_message_id' => '消息ID无效',
            'empty_text' => '消息内容为空',
            'invalid_group_id' => '群ID无效',
            default => '已忽略',
        };

        $this->markInboxResult($status, $resultText, null, [
            '处理场景' => '忽略分支',
            '忽略原因代码' => $reason,
            '上下文' => $context,
        ]);

        Log::channel('stderr')->info('telegram_webhook_ignored', array_merge([
            'reason' => $reason,
        ], $context));

        return response()->json([
            'ok' => true,
            'ignored' => $reason,
        ]);
    }

    private function extractMessage(array $payload): array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            $candidate = $payload[$key] ?? null;
            if (is_array($candidate)) {
                return [$candidate, $key];
            }
        }

        return [null, null];
    }

    private function readPayload(Request $request): ?array
    {
        $jsonPayload = $request->json()->all();
        if (is_array($jsonPayload) && $jsonPayload !== []) {
            return $jsonPayload;
        }

        $raw = (string) $request->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        $allPayload = $request->all();
        if (is_array($allPayload) && $allPayload !== []) {
            return $allPayload;
        }

        return null;
    }

    private function isDuplicateUpdate(?int $updateId): bool
    {
        if ($updateId === null) {
            return false;
        }

        // Telegram 可能因为网络抖动重试同一 update_id，这里做短期去重。
        return !Cache::add("telegram:webhook:update:{$updateId}", 1, now()->addMinutes(5));
    }

    private function recordInboxReceived(array $payload, ?int $updateId): ?int
    {
        [$message, $updateType] = $this->extractMessage($payload);
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
        $chatId = $this->toIntOrNull($chat['id'] ?? null);
        $messageId = $this->toIntOrNull($message['message_id'] ?? null);
        $messageText = is_array($message) ? $this->pickMessageText($message) : null;
        $messageText = is_string($messageText) ? mb_substr($messageText, 0, 2000) : null;
        $payloadText = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $now = now();

        if ($updateId === null) {
            return (int) DB::table('tg_update_inbox')->insertGetId([
                'update_id' => null,
                'update_type' => $updateType,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message_text' => $messageText,
                'payload' => $payloadText,
                'status' => '已接收',
                'result_code' => '',
                'process_detail' => null,
                'attempt_count' => 1,
                'last_error' => null,
                'received_at' => $now,
                'processed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $exists = DB::table('tg_update_inbox')
            ->where('update_id', $updateId)
            ->first();

        if ($exists === null) {
            return (int) DB::table('tg_update_inbox')->insertGetId([
                'update_id' => $updateId,
                'update_type' => $updateType,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message_text' => $messageText,
                'payload' => $payloadText,
                'status' => '已接收',
                'result_code' => '',
                'process_detail' => null,
                'attempt_count' => 1,
                'last_error' => null,
                'received_at' => $now,
                'processed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $nextAttemptCount = (int) ($exists->attempt_count ?? 1) + 1;
        DB::table('tg_update_inbox')
            ->where('id', (int) $exists->id)
            ->update([
                'update_type' => $updateType,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message_text' => $messageText,
                'payload' => $payloadText,
                'status' => '已接收',
                'result_code' => '',
                'process_detail' => null,
                'attempt_count' => $nextAttemptCount,
                'last_error' => null,
                'received_at' => $now,
                'processed_at' => null,
                'updated_at' => $now,
            ]);

        return (int) $exists->id;
    }

    private function markInboxResult(string $status, string $resultCode, ?string $lastError = null, ?array $processDetail = null): void
    {
        if ($this->currentInboxId === null || $this->currentInboxId <= 0) {
            return;
        }

        $detailText = null;
        if (is_array($processDetail) && $processDetail !== []) {
            $detailText = (string) json_encode($processDetail, JSON_UNESCAPED_UNICODE);
        }

        DB::table('tg_update_inbox')
            ->where('id', $this->currentInboxId)
            ->update([
                'status' => $status,
                'result_code' => $resultCode,
                'process_detail' => $detailText,
                'last_error' => $lastError !== null && trim($lastError) !== '' ? '异常信息：' . $lastError : null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function logIncomingGroupMessage(int $rawGroupId, int $tgMsgId, string $text, array $context): void
    {
        Log::channel('stderr')->info('telegram_group_message', [
            'tg_gid' => $rawGroupId,
            'tg_msg_id' => $tgMsgId,
            'tg_uid' => $context['tg_uid'] ?? null,
            'sender' => $context['sender'] ?? '',
            'chat_type' => $context['chat_type'] ?? '',
            'chat_title' => $context['chat_title'] ?? '',
            'text' => mb_substr($text, 0, 500),
            'update_id' => $context['update_id'] ?? null,
        ]);
    }

    private function pickMessageText(array $message): ?string
    {
        $text = $message['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        $caption = $message['caption'] ?? null;
        if (is_string($caption) && trim($caption) !== '') {
            return $caption;
        }

        return null;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function resolveGroupIds(int $rawGroupId): array
    {
        $groupIds = [$rawGroupId];

        // 兼容历史正数群 ID 存量，优先尝试原始值，未命中再尝试绝对值。
        if ($rawGroupId < 0) {
            $groupIds[] = abs($rawGroupId);
        }

        return array_values(array_unique($groupIds));
    }
}
