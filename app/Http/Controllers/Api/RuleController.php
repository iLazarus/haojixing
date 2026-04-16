<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\RuleStoreRequest;
use App\Http\Requests\RuleUpdateRequest;
use App\Services\Rule\RuleService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RuleController extends Controller
{
    use ApiResponder;

    public function __construct(private readonly RuleService $ruleService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 100);
        $data = $this->ruleService->list(max(1, min(500, $limit)));

        return $this->success($request, $data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $rule = $this->ruleService->findById($id);
        if ($rule === null) {
            return $this->error($request, 'rule not found', 404, 40405);
        }

        return $this->success($request, $rule);
    }

    public function store(RuleStoreRequest $request): JsonResponse
    {
        $rule = $this->ruleService->create($request->validated());

        return $this->success($request, $rule, 201);
    }

    public function update(RuleUpdateRequest $request, int $id): JsonResponse
    {
        $rule = $this->ruleService->updateById($id, $request->validated());
        if ($rule === null) {
            return $this->error($request, 'rule not found', 404, 40405);
        }

        return $this->success($request, $rule);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->ruleService->deleteById($id);

        return $this->success($request, ['deleted' => $deleted]);
    }
}
