<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\Rule\RuleMatchingService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly RuleMatchingService $ruleMatchingService)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $this->readPayload($request);
        if ($payload === null || $payload === []) {
            return $this->ignored('invalid_payload');
        }

        $createdGroupId = $this->syncGroupWhenBotAdded($payload);
        $memberChangedGroupId = $this->extractMemberChangedGroupId($payload);

        if ($createdGroupId !== null) {
            $this->syncUsersWhenGroupMemberChanged($createdGroupId, $payload);
        }

        if ($memberChangedGroupId !== null && $memberChangedGroupId !== $createdGroupId) {
            $this->syncUsersWhenGroupMemberChanged($memberChangedGroupId, $payload);
        }

        $chatId = $this->extractChatId($payload);
        if ($chatId === null) {
            return $this->ignored('missing_chat_id', [
                'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
                'update_keys' => array_keys($payload),
            ]);
        }

        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            Log::channel('stderr')->warning('telegram_bot_token_missing');

            return response()->json([
                'ok' => false,
                'error' => 'telegram_bot_token_missing',
            ], 500);
        }

        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => 'get',
        ]);

        if (!$response->ok()) {
            Log::channel('stderr')->warning('telegram_send_message_failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    private function syncGroupWhenBotAdded(array $payload): ?int
    {
        $context = $this->extractBotJoinContext($payload);
        if ($context === null) {
            return null;
        }

        $checkResponse = $this->callInternalJsonApi('GET', '/api/v1/groups/' . $context['tg_gid']);
        if ($checkResponse['status'] === 200) {
            return null;
        }

        if ($checkResponse['status'] !== 404) {
            Log::channel('stderr')->warning('group_sync_check_failed', [
                'tg_gid' => $context['tg_gid'],
                'status' => $checkResponse['status'],
                'body' => mb_substr($checkResponse['body'], 0, 500),
            ]);

            return null;
        }

        $createResponse = $this->callInternalJsonApi('POST', '/api/v1/groups', [
            'tg_gid' => $context['tg_gid'],
            'tg_oid' => $context['tg_oid'],
        ]);

        if ($createResponse['status'] < 200 || $createResponse['status'] >= 300) {
            Log::channel('stderr')->warning('group_sync_create_failed', [
                'tg_gid' => $context['tg_gid'],
                'tg_oid' => $context['tg_oid'],
                'status' => $createResponse['status'],
                'body' => mb_substr($createResponse['body'], 0, 500),
            ]);

            return null;
        }

        Log::channel('stderr')->info('group_auto_created_from_webhook', [
            'tg_gid' => $context['tg_gid'],
            'tg_oid' => $context['tg_oid'],
            'chat_title' => $context['chat_title'],
            'chat_type' => $context['chat_type'],
            'update_id' => $context['update_id'],
        ]);

        return $context['tg_gid'];
    }

    private function syncUsersWhenGroupMemberChanged(int $tgGid, array $payload): void
    {
        $users = [];

        $this->collectVisibleUsersFromPayload($users, $payload);

        // Telegram Bot API 无法直接枚举全量群成员，每次变动时尽量刷新管理员列表。
        $token = (string) config('services.telegram.bot_token', '');
        if ($token !== '') {
            $adminResponse = Http::timeout(10)->acceptJson()->post("https://api.telegram.org/bot{$token}/getChatAdministrators", [
                'chat_id' => $tgGid,
            ]);

            if ($adminResponse->ok()) {
                $admins = $adminResponse->json('result');
                if (is_array($admins)) {
                    foreach ($admins as $admin) {
                        if (!is_array($admin)) {
                            continue;
                        }

                        $adminUser = is_array($admin['user'] ?? null) ? $admin['user'] : null;
                        if ($adminUser === null) {
                            continue;
                        }

                        $this->pushTelegramUser($users, $adminUser);
                    }
                }
            } else {
                Log::channel('stderr')->warning('group_user_sync_admin_fetch_failed', [
                    'tg_gid' => $tgGid,
                    'status' => $adminResponse->status(),
                    'body' => mb_substr((string) $adminResponse->body(), 0, 500),
                ]);
            }
        }

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($users as $user) {
            $upsert = $this->upsertTelegramUser($user);
            $summary[$upsert]++;
        }

        Log::channel('stderr')->info('group_user_sync_finished', [
            'tg_gid' => $tgGid,
            'candidate_count' => count($users),
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
        ]);
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

    private function extractMemberChangedGroupId(array $payload): ?int
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message !== null) {
            $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
            $chatType = is_string($chat['type'] ?? null) ? strtolower((string) $chat['type']) : '';
            if (in_array($chatType, ['group', 'supergroup'], true)) {
                $hasJoin = is_array($message['new_chat_members'] ?? null) && $message['new_chat_members'] !== [];
                $hasLeave = is_array($message['left_chat_member'] ?? null);
                if ($hasJoin || $hasLeave) {
                    return $this->toIntOrNull($chat['id'] ?? null);
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
                    return $this->toIntOrNull($chat['id'] ?? null);
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
                    return $this->toIntOrNull($chat['id'] ?? null);
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
