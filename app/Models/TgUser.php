<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Telegram 用户模型。
 *
 * 保存用户基础资料（UID、用户名、昵称）。
 */
class TgUser extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'tg_user';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 用户 ID
        'tg_uid',
        // Telegram 用户名
        'tg_username',
        // Telegram 昵称
        'tg_nickname',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];
}
