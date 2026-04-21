<?php

declare(strict_types=1);

namespace App\Services\Group;

use App\Models\AppMember;
use App\Services\Member\MemberService;
use App\Services\User\UserService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GroupSyncService
{
    public function __construct(
        private readonly GroupService $groupService,
        private readonly UserService $userService,
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $seedUsers
     * @return array<string, mixed>
     */
    public function refresh(
        int $tgGid,
        ?int $triggerUid = null,
        string $triggerNickname = '',
        array $seedUsers = [],
        ?string $fallbackGroupName = null,
    ): array {
        $groupName = trim((string) ($fallbackGroupName ?? ''));
        $admins = [];
        $adminUserIds = [];
        $ownerUid = $triggerUid;
        $ownerNickname = trim($triggerNickname);

        $chatResponse = $this->callTelegramApi('getChat', ['chat_id' => $tgGid]);
        if ($chatResponse['ok']) {
            $chatTitle = trim((string) ($chatResponse['result']['title'] ?? ''));
            if ($chatTitle !== '') {
                $groupName = $chatTitle;
            }
        }

        $adminResponse = $this->callTelegramApi('getChatAdministrators', ['chat_id' => $tgGid]);
        if ($adminResponse['ok']) {
            $adminList = $adminResponse['result'];
            if (is_array($adminList)) {
                foreach ($adminList as $adminRow) {
                    if (!is_array($adminRow)) {
                        continue;
                    }

                    $adminUser = is_array($adminRow['user'] ?? null) ? $adminRow['user'] : null;
                    if ($adminUser === null) {
                        continue;
                    }

                    $adminUid = $this->toIntOrNull($adminUser['id'] ?? null);
                    if ($adminUid === null || $adminUid <= 0) {
                        continue;
                    }

                    $admins[$adminUid] = $adminUser;
                    $adminUserIds[$adminUid] = true;

                    $status = is_string($adminRow['status'] ?? null) ? trim((string) $adminRow['status']) : '';
                    if ($status === 'creator') {
                        $ownerUid = $adminUid;
                        $nickname = $this->normalizeTelegramNickname($adminUser);
                        if ($nickname !== null && $nickname !== '') {
                            $ownerNickname = $nickname;
                        }
                    }
                }
            }
        }

        $candidateUsers = $seedUsers;
        foreach ($admins as $adminUid => $adminUser) {
            $candidateUsers[$adminUid] = $adminUser;
        }

        if ($triggerUid !== null && $triggerUid > 0 && !isset($candidateUsers[$triggerUid])) {
            $candidateUsers[$triggerUid] = [
                'id' => $triggerUid,
                'first_name' => $triggerNickname,
            ];
        }

        $groupSync = $this->upsertGroup($tgGid, $ownerUid, $groupName, $ownerNickname);
        $userSync = $this->upsertUsers($candidateUsers);
        $memberSync = $this->upsertMembers($tgGid, $groupName, $candidateUsers, $adminUserIds);

        return [
            'tg_gid' => $tgGid,
            'group' => $groupSync,
            'users' => $userSync,
            'members' => $memberSync,
            'candidate_user_count' => count($candidateUsers),
            'admin_count' => count($adminUserIds),
            'chat_synced' => (bool) $chatResponse['ok'],
        ];
    }

    public function isGroupOwner(int $tgGid, ?int $tgUid): bool
    {
        if ($tgUid === null || $tgUid <= 0) {
            return false;
        }

        $response = $this->callTelegramApi('getChatMember', [
            'chat_id' => $tgGid,
            'user_id' => $tgUid,
        ]);

        if (!$response['ok']) {
            return false;
        }

        $status = is_string($response['result']['status'] ?? null)
            ? trim((string) $response['result']['status'])
            : '';

        return $status === 'creator';
    }

    /**
     * @param array<int, array<string, mixed>> $candidateUsers
     * @return array<string, int>
     */
    private function upsertUsers(array $candidateUsers): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($candidateUsers as $candidateUser) {
            if (!is_array($candidateUser)) {
                $summary['skipped']++;
                continue;
            }

            $tgUid = $this->toIntOrNull($candidateUser['id'] ?? null);
            if ($tgUid === null || $tgUid <= 0) {
                $summary['skipped']++;
                continue;
            }

            $payload = [
                'tg_uid' => $tgUid,
                'tg_username' => $this->normalizeTelegramUsername($candidateUser),
                'tg_nickname' => $this->normalizeTelegramNickname($candidateUser),
            ];

            try {
                $current = $this->userService->findByTgUid($tgUid);
                if ($current === null) {
                    $this->userService->create($payload);
                    $summary['created']++;

                    continue;
                }

                $patch = [];
                $currentUsername = $current->tg_username !== null ? (string) $current->tg_username : null;
                $currentNickname = $current->tg_nickname !== null ? (string) $current->tg_nickname : null;
                if ($currentUsername !== $payload['tg_username']) {
                    $patch['tg_username'] = $payload['tg_username'];
                }
                if ($currentNickname !== $payload['tg_nickname']) {
                    $patch['tg_nickname'] = $payload['tg_nickname'];
                }

                if ($patch === []) {
                    $summary['skipped']++;

                    continue;
                }

                $this->userService->updateByTgUid($tgUid, $patch);
                $summary['updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                Log::channel('stderr')->warning('group_user_sync_upsert_failed', [
                    'tg_uid' => $tgUid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $candidateUsers
     * @param array<int, bool> $adminUserIds
     * @return array<string, int>
     */
    private function upsertMembers(int $tgGid, string $groupName, array $candidateUsers, array $adminUserIds): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $memberTgGid = $this->normalizeMemberGroupId($tgGid);

        foreach ($candidateUsers as $candidateUser) {
            if (!is_array($candidateUser)) {
                $summary['skipped']++;
                continue;
            }

            $tgUid = $this->toIntOrNull($candidateUser['id'] ?? null);
            if ($tgUid === null || $tgUid <= 0) {
                $summary['skipped']++;
                continue;
            }

            $role = isset($adminUserIds[$tgUid]) ? AppMember::ROLE_OPERATOR : AppMember::ROLE_CONSUMER;
            $nickname = (string) ($this->normalizeTelegramNickname($candidateUser) ?? '');

            try {
                $current = $this->memberService->findOne($memberTgGid, $tgUid);
                if ($current === null) {
                    $this->memberService->create([
                        'tg_gid' => $memberTgGid,
                        'tg_uid' => $tgUid,
                        'tg_g_name' => $groupName,
                        'tg_nickname' => $nickname,
                        'role' => $role,
                        'is_active' => true,
                    ]);
                    $summary['created']++;

                    continue;
                }

                $patch = [];
                if ((string) $current->role !== $role) {
                    $patch['role'] = $role;
                }
                if ((bool) $current->is_active !== true) {
                    $patch['is_active'] = true;
                }
                if ((string) ($current->tg_g_name ?? '') !== $groupName) {
                    $patch['tg_g_name'] = $groupName;
                }
                if ((string) ($current->tg_nickname ?? '') !== $nickname) {
                    $patch['tg_nickname'] = $nickname;
                }

                if ($patch === []) {
                    $summary['skipped']++;

                    continue;
                }

                $this->memberService->updateOne($memberTgGid, $tgUid, $patch);
                $summary['updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                Log::channel('stderr')->warning('group_member_sync_upsert_failed', [
                    'tg_gid' => $memberTgGid,
                    'tg_uid' => $tgUid,
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function upsertGroup(int $tgGid, ?int $ownerUid, string $groupName, string $ownerNickname): array
    {
        try {
            $current = $this->groupService->findByTgGid($tgGid);
            if ($current === null) {
                if ($ownerUid === null || $ownerUid <= 0) {
                    return [
                        'status' => 'failed',
                        'reason' => 'missing_owner_uid',
                        'tg_g_name' => $groupName,
                    ];
                }

                $created = $this->groupService->create([
                    'tg_gid' => $tgGid,
                    'tg_oid' => $ownerUid,
                    'tg_g_name' => $groupName,
                    'tg_o_nickname' => $ownerNickname,
                ]);

                return [
                    'status' => 'created',
                    'tg_gid' => (int) $created->tg_gid,
                    'tg_oid' => (int) $created->tg_oid,
                    'tg_g_name' => (string) ($created->tg_g_name ?? ''),
                    'tg_o_nickname' => (string) ($created->tg_o_nickname ?? ''),
                ];
            }

            $patch = [];
            if ((string) ($current->tg_g_name ?? '') !== $groupName) {
                $patch['tg_g_name'] = $groupName;
            }
            if ($ownerNickname !== '' && (string) ($current->tg_o_nickname ?? '') !== $ownerNickname) {
                $patch['tg_o_nickname'] = $ownerNickname;
            }

            if ($patch === []) {
                return [
                    'status' => 'skipped',
                    'tg_gid' => (int) $current->tg_gid,
                    'tg_oid' => (int) $current->tg_oid,
                    'tg_g_name' => (string) ($current->tg_g_name ?? ''),
                    'tg_o_nickname' => (string) ($current->tg_o_nickname ?? ''),
                ];
            }

            $updated = $this->groupService->updateByTgGid($tgGid, $patch);
            if ($updated === null) {
                return [
                    'status' => 'failed',
                    'reason' => 'update_not_found',
                    'tg_g_name' => $groupName,
                ];
            }

            return [
                'status' => 'updated',
                'tg_gid' => (int) $updated->tg_gid,
                'tg_oid' => (int) $updated->tg_oid,
                'tg_g_name' => (string) ($updated->tg_g_name ?? ''),
                'tg_o_nickname' => (string) ($updated->tg_o_nickname ?? ''),
            ];
        } catch (Throwable $e) {
            Log::channel('stderr')->warning('group_sync_upsert_group_failed', [
                'tg_gid' => $tgGid,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'reason' => 'exception',
                'message' => $e->getMessage(),
                'tg_g_name' => $groupName,
            ];
        }
    }

    /**
     * @return array{ok: bool, status: int, result: array<string, mixed>|array<int, mixed>|null}
     */
    private function callTelegramApi(string $method, array $payload): array
    {
        $token = trim((string) config('services.telegram.bot_token', ''));
        if ($token === '') {
            return [
                'ok' => false,
                'status' => 0,
                'result' => null,
            ];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            $body = $response->json();

            if (!$response->ok() || !is_array($body) || ($body['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'result' => null,
                ];
            }

            $result = $body['result'] ?? null;

            return [
                'ok' => true,
                'status' => $response->status(),
                'result' => is_array($result) ? $result : null,
            ];
        } catch (Throwable $e) {
            Log::channel('stderr')->warning('group_sync_call_telegram_failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'result' => null,
            ];
        }
    }

    private function normalizeMemberGroupId(int $tgGid): int
    {
        return $tgGid < 0 ? abs($tgGid) : $tgGid;
    }

    private function normalizeTelegramUsername(array $telegramUser): ?string
    {
        $username = is_string($telegramUser['username'] ?? null) ? trim((string) $telegramUser['username']) : '';

        return $username === '' ? null : mb_substr($username, 0, 64);
    }

    private function normalizeTelegramNickname(array $telegramUser): ?string
    {
        $nickname = trim((string) ($telegramUser['first_name'] ?? ''));
        $lastName = trim((string) ($telegramUser['last_name'] ?? ''));
        if ($nickname !== '' && $lastName !== '') {
            $nickname .= ' ' . $lastName;
        } elseif ($nickname === '' && $lastName !== '') {
            $nickname = $lastName;
        }

        return $nickname === '' ? null : mb_substr($nickname, 0, 128);
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
}
