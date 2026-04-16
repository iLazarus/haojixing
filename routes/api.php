<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupRuleController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\RuleEngineController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::get('/groups/{tgGid}', [GroupController::class, 'show']);
    Route::patch('/groups/{tgGid}', [GroupController::class, 'update']);
    Route::delete('/groups/{tgGid}', [GroupController::class, 'destroy']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{tgUid}', [UserController::class, 'show']);
    Route::patch('/users/{tgUid}', [UserController::class, 'update']);
    Route::delete('/users/{tgUid}', [UserController::class, 'destroy']);

    Route::post('/members', [MemberController::class, 'store']);
    Route::get('/groups/{tgGid}/members', [MemberController::class, 'listByGroup']);
    Route::get('/groups/{tgGid}/members/{tgUid}', [MemberController::class, 'show']);
    Route::patch('/groups/{tgGid}/members/{tgUid}', [MemberController::class, 'update']);
    Route::delete('/groups/{tgGid}/members/{tgUid}', [MemberController::class, 'destroy']);

    Route::get('/rules', [RuleController::class, 'index']);
    Route::post('/rules', [RuleController::class, 'store']);
    Route::get('/rules/{id}', [RuleController::class, 'show']);
    Route::patch('/rules/{id}', [RuleController::class, 'update']);
    Route::delete('/rules/{id}', [RuleController::class, 'destroy']);

    Route::get('/groups/{tgGid}/rules', [GroupRuleController::class, 'index']);
    Route::post('/groups/{tgGid}/rules', [GroupRuleController::class, 'store']);
    Route::patch('/groups/{tgGid}/rules/{appRuleId}', [GroupRuleController::class, 'update']);
    Route::delete('/groups/{tgGid}/rules/{appRuleId}', [GroupRuleController::class, 'destroy']);
    Route::post('/groups/{tgGid}/rules/match', [RuleEngineController::class, 'match']);

    Route::post('/ledgers', [LedgerController::class, 'store']);
    Route::get('/ledgers/{id}', [LedgerController::class, 'show']);
    Route::patch('/ledgers/{id}', [LedgerController::class, 'update']);
    Route::delete('/ledgers/{id}', [LedgerController::class, 'destroy']);
    Route::patch('/ledgers/{id}/soft-delete', [LedgerController::class, 'softDelete']);
    Route::get('/groups/{tgGid}/ledgers', [LedgerController::class, 'listByGroup']);

    // 幂等入账接口，仅处理账单写入，不处理 group/user/member 管理。
    Route::post('/ledger/ingest', [LedgerController::class, 'ingest']);
});
