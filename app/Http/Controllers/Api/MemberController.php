<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\MemberStoreRequest;
use App\Http\Requests\MemberUpdateRequest;
use App\Services\Member\MemberService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MemberController extends Controller
{
    use ApiResponder;

    public function __construct(private readonly MemberService $memberService)
    {
    }

    public function listByGroup(int $tgGid, Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $data = $this->memberService->listByGroup($tgGid, max(1, min(1000, $limit)));

        return $this->success($request, $data);
    }

    public function show(int $tgGid, int $tgUid): JsonResponse
    {
        $member = $this->memberService->findOne($tgGid, $tgUid);
        if ($member === null) {
            return $this->error($request, 'member not found', 404, 40403);
        }

        return $this->success($request, $member);
    }

    public function store(MemberStoreRequest $request): JsonResponse
    {
        $member = $this->memberService->create($request->validated());

        return $this->success($request, $member, 201);
    }

    public function update(int $tgGid, int $tgUid, MemberUpdateRequest $request): JsonResponse
    {
        $member = $this->memberService->updateOne($tgGid, $tgUid, $request->validated());

        if ($member === null) {
            return $this->error($request, 'member not found', 404, 40403);
        }

        return $this->success($request, $member);
    }

    public function destroy(Request $request, int $tgGid, int $tgUid): JsonResponse
    {
        $deleted = $this->memberService->deleteOne($tgGid, $tgUid);

        return $this->success($request, ['deleted' => $deleted]);
    }
}
