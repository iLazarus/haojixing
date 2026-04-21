<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\GroupRuleStoreRequest;
use App\Http\Requests\GroupRuleUpdateRequest;
use App\Services\Rule\GroupRuleService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GroupRuleController extends Controller
{
    use ApiResponder;

    public function __construct(private readonly GroupRuleService $groupRuleService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $tgGidRaw = $request->query('tg_gid');
        $tgGid = is_numeric($tgGidRaw) ? (int) $tgGidRaw : null;
        $data = $this->groupRuleService->list(max(1, min(1000, $limit)), $tgGid);

        return $this->success($request, $data);
    }

    public function index(Request $request, int $tgGid): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $data = $this->groupRuleService->listByGroup($tgGid, max(1, min(1000, $limit)));

        return $this->success($request, $data);
    }

    public function store(GroupRuleStoreRequest $request, int $tgGid): JsonResponse
    {
        $payload = $request->validated();
        $payload['tg_gid'] = $tgGid;
        $rule = $this->groupRuleService->create($payload);

        return $this->success($request, $rule, 201);
    }

    public function update(GroupRuleUpdateRequest $request, int $tgGid, int $appRuleId): JsonResponse
    {
        $rule = $this->groupRuleService->updateOne($tgGid, $appRuleId, $request->validated());
        if ($rule === null) {
            return $this->error($request, 'group rule not found', 404, 40406);
        }

        return $this->success($request, $rule);
    }

    public function destroy(Request $request, int $tgGid, int $appRuleId): JsonResponse
    {
        $deleted = $this->groupRuleService->deleteOne($tgGid, $appRuleId);

        return $this->success($request, ['deleted' => $deleted]);
    }
}
