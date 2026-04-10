<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AppMember extends Model
{
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_CONSUMER = 'consumer';

    protected $table = 'app_member';

    public $timestamps = false;

    protected $fillable = [
        'tg_gid',
        'tg_uid',
        'role',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function assertRole(string $role): void
    {
        if (!in_array($role, [self::ROLE_OPERATOR, self::ROLE_CONSUMER], true)) {
            throw new InvalidArgumentException('role 仅允许 operator 或 consumer');
        }
    }
}
