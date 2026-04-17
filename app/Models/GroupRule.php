<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 群规则绑定模型。
 *
 * 记录群与规则的关联关系、优先级及命中后是否停止匹配。
 */
class GroupRule extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'group_rule';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 群 ID
        'tg_gid',
        // 关联的应用规则 ID
        'app_rule_id',
        // 执行优先级（越小越先匹配）
        'priority',
        // 命中后是否停止继续匹配
        'stop_on_match',
        // 群规则绑定是否启用
        'is_active',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];

    /** @var array<string, string> 字段类型转换规则 */
    protected $casts = [
        'priority' => 'integer',
        'stop_on_match' => 'boolean',
        'is_active' => 'boolean',
    ];
}
