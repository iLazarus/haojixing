<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\TgUpdateInbox;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TgUpdateInboxController extends Controller
{
    use ApiResponder;

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min(1000, $limit));

        $query = TgUpdateInbox::query()->orderByDesc('id');

        $updateId = $request->query('update_id');
        if (is_numeric($updateId)) {
            $query->where('update_id', (int) $updateId);
        }

        $chatId = $request->query('chat_id');
        if (is_numeric($chatId)) {
            $query->where('chat_id', (int) $chatId);
        }

        $messageId = $request->query('message_id');
        if (is_numeric($messageId)) {
            $query->where('message_id', (int) $messageId);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->limit($limit)->get();

        return $this->success($request, $rows);
    }
}
