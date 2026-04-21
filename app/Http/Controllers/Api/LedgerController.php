<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\LedgerStoreRequest;
use App\Http\Requests\LedgerUpdateRequest;
use App\Http\Requests\LedgerIngestRequest;
use App\Services\Ledger\LedgerIngestService;
use App\Services\Ledger\LedgerService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LedgerController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly LedgerIngestService $ledgerIngestService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $tgGidRaw = $request->query('tg_gid');
        $tgGid = is_numeric($tgGidRaw) ? (int) $tgGidRaw : null;
        $data = $this->ledgerService->list(max(1, min(1000, $limit)), $tgGid);

        return $this->success($request, $data);
    }

    public function listByGroup(int $tgGid, Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $data = $this->ledgerService->listByGroup($tgGid, max(1, min(1000, $limit)));

        return $this->success($request, $data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ledger = $this->ledgerService->findById($id);
        if ($ledger === null) {
            return $this->error($request, 'ledger not found', 404, 40404);
        }

        return $this->success($request, $ledger);
    }

    public function store(LedgerStoreRequest $request): JsonResponse
    {
        $ledger = $this->ledgerService->create($request->validated());

        return $this->success($request, $ledger, 201);
    }

    public function update(int $id, LedgerUpdateRequest $request): JsonResponse
    {
        $ledger = $this->ledgerService->updateById($id, $request->validated());
        if ($ledger === null) {
            return $this->error($request, 'ledger not found', 404, 40404);
        }

        return $this->success($request, $ledger);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->ledgerService->deleteById($id);

        return $this->success($request, ['deleted' => $deleted]);
    }

    public function softDelete(Request $request, int $id): JsonResponse
    {
        $ledger = $this->ledgerService->softDeleteById($id);
        if ($ledger === null) {
            return $this->error($request, 'ledger not found', 404, 40404);
        }

        return $this->success($request, $ledger);
    }

    public function ingest(LedgerIngestRequest $request): JsonResponse
    {
        $ledger = $this->ledgerIngestService->ingest($request->validated());

        return $this->success($request, [
                'ledger_id' => $ledger->id,
                'tg_gid' => $ledger->tg_gid,
                'tg_msg_id' => $ledger->tg_msg_id,
                'amount_cent' => $ledger->amount,
            'currency_type' => $ledger->currency_type,
                'created_at' => $ledger->created_at,
                'updated_at' => $ledger->updated_at,
            ]);
    }
}
