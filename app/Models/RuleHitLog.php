<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 规则命中日志模型。
 *
 * 用于记录规则命中明细并支撑同消息幂等去重。
 */
class RuleHitLog extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'rule_hit_log';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 群 ID
        'tg_gid',
        // Telegram 消息 ID
        'tg_msg_id',
        // 命中的应用规则 ID
        'app_rule_id',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];
}
