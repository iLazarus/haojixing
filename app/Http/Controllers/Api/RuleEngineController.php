<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\RuleMatchRequest;
use App\Services\Rule\RuleMatchingService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class RuleEngineController extends Controller
{
    use ApiResponder;

    public function __construct(private readonly RuleMatchingService $ruleMatchingService)
    {
    }

    public function match(RuleMatchRequest $request, int $tgGid): JsonResponse
    {
        $data = $request->validated();

        $result = $this->ruleMatchingService->match(
            $tgGid,
            (int) $data['tg_msg_id'],
            (string) $data['message'],
            (bool) ($data['execute_api'] ?? false),
            is_array($data['context'] ?? null) ? $data['context'] : []
        );

        return $this->success($request, $result);
    }
}
