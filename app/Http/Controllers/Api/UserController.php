<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Services\User\UserService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserController extends Controller
{
    use ApiResponder;

    public function __construct(private readonly UserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $data = $this->userService->list(max(1, min(500, $limit)));

        return $this->success($request, $data);
    }

    public function show(int $tgUid): JsonResponse
    {
        $user = $this->userService->findByTgUid($tgUid);
        if ($user === null) {
            return $this->error($request, 'user not found', 404, 40402);
        }

        return $this->success($request, $user);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->success($request, $user, 201);
    }

    public function update(UserUpdateRequest $request, int $tgUid): JsonResponse
    {
        $user = $this->userService->updateByTgUid($tgUid, $request->validated());
        if ($user === null) {
            return $this->error($request, 'user not found', 404, 40402);
        }

        return $this->success($request, $user);
    }

    public function destroy(Request $request, int $tgUid): JsonResponse
    {
        $deleted = $this->userService->deleteByTgUid($tgUid);

        return $this->success($request, ['deleted' => $deleted]);
    }
}
