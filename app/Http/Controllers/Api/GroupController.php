<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\GroupSyncRequest;
use App\Http\Requests\GroupStoreRequest;
use App\Http\Requests\GroupUpdateRequest;
use App\Services\Group\GroupSyncService;
use App\Services\Group\GroupService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GroupController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly GroupService $groupService,
        private readonly GroupSyncService $groupSyncService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $data = $this->groupService->list(max(1, min(500, $limit)));

        return $this->success($request, $data);
    }

    public function show(Request $request, int $tgGid): JsonResponse
    {
        $group = $this->groupService->findByTgGid($tgGid);
        if ($group === null) {
            return $this->error($request, 'group not found', 404, 40401);
        }

        return $this->success($request, $group);
    }

    public function store(GroupStoreRequest $request): JsonResponse
    {
        $group = $this->groupService->create($request->validated());

        return $this->success($request, $group, 201);
    }

    public function update(GroupUpdateRequest $request, int $tgGid): JsonResponse
    {
        $group = $this->groupService->updateByTgGid($tgGid, $request->validated());
        if ($group === null) {
            return $this->error($request, 'group not found', 404, 40401);
        }

        return $this->success($request, $group);
    }

    public function destroy(Request $request, int $tgGid): JsonResponse
    {
        $deleted = $this->groupService->deleteByTgGid($tgGid);

        return $this->success($request, ['deleted' => $deleted]);
    }

    public function sync(GroupSyncRequest $request, int $tgGid): JsonResponse
    {
        $payload = $request->validated();

        $data = $this->groupSyncService->refresh(
            $tgGid,
            isset($payload['trigger_tg_uid']) ? (int) $payload['trigger_tg_uid'] : null,
            (string) ($payload['trigger_nickname'] ?? ''),
            [],
            isset($payload['fallback_group_name']) ? (string) $payload['fallback_group_name'] : null,
        );

        return $this->success($request, $data);
    }
}
