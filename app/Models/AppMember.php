<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * 群成员关系模型。
 *
 * 记录用户在群内的角色与激活状态。
 */
class AppMember extends Model
{
    /** 操作员角色 */
    public const ROLE_OPERATOR = 'operator';
    /** 消费者角色 */
    public const ROLE_CONSUMER = 'consumer';

    /** @var string 对应的数据表名 */
    protected $table = 'app_member';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 群 ID
        'tg_gid',
        // Telegram 用户 ID
        'tg_uid',
        // Telegram 群名称
        'tg_g_name',
        // Telegram 昵称
        'tg_nickname',
        // 成员角色（operator/consumer）
        'role',
        // 成员是否启用
        'is_active',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];

    /** @var array<string, string> 字段类型转换规则 */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * 断言角色值是否合法。
     */
    public static function assertRole(string $role): void
    {
        if (!in_array($role, [self::ROLE_OPERATOR, self::ROLE_CONSUMER], true)) {
            throw new InvalidArgumentException('role 仅允许 operator 或 consumer');
        }
    }
}
