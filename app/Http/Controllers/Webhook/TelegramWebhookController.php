<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\Rule\RuleMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly RuleMatchingService $ruleMatchingService)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (!is_array($payload)) {
            return response()->json(['ok' => true, 'ignored' => 'invalid_payload']);
        }

        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message === null) {
            return response()->json(['ok' => true, 'ignored' => 'unsupported_update']);
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : null;
        if ($chat === null) {
            return response()->json(['ok' => true, 'ignored' => 'missing_chat']);
        }

        $chatType = is_string($chat['type'] ?? null) ? strtolower(trim((string) $chat['type'])) : '';
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return response()->json(['ok' => true, 'ignored' => 'not_group_chat']);
        }

        $tgMsgId = $this->toIntOrNull($message['message_id'] ?? null);
        if ($tgMsgId === null || $tgMsgId <= 0) {
            return response()->json(['ok' => true, 'ignored' => 'invalid_message_id']);
        }

        $text = $this->pickMessageText($message);
        if ($text === null || trim($text) === '') {
            return response()->json(['ok' => true, 'ignored' => 'empty_text']);
        }

        $rawGroupId = $this->toIntOrNull($chat['id'] ?? null);
        if ($rawGroupId === null) {
            return response()->json(['ok' => true, 'ignored' => 'invalid_group_id']);
        }

        $context = [
            'sender' => is_array($message['from'] ?? null) ? (string) ($message['from']['username'] ?? $message['from']['first_name'] ?? '') : '',
            'tg_uid' => $this->toIntOrNull($message['from']['id'] ?? null),
            'chat_type' => $chatType,
            'chat_title' => is_string($chat['title'] ?? null) ? (string) $chat['title'] : '',
            'update_id' => $this->toIntOrNull($payload['update_id'] ?? null),
        ];

        $this->logIncomingGroupMessage($rawGroupId, $tgMsgId, $text, $context);

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
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'matched' => (int) ($result['hit_count'] ?? 0),
            'data' => $result,
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
