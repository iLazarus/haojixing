<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Telegram 群配置模型。
 *
 * 存储群级开关、汇率费率与结算周期配置。
 */
class TgGroup extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'tg_group';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 群 ID
        'tg_gid',
        // 群主 Telegram 用户 ID
        'tg_oid',
        // 群是否开启记账/结算
        'is_open',
        // 基础币种标识
        'base_currency',
        // 报价币种标识
        'quote_currency',
        // 汇率
        'exchange_rate',
        // 费率（百分比）
        'fee_rate',
        // 周期结算时点（小时）
        'period_point',
        // 周期时长（分钟）
        'period_duration',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];

    /** @var array<string, string> 字段类型转换规则 */
    protected $casts = [
        'is_open' => 'boolean',
        'exchange_rate' => 'decimal:10',
        'fee_rate' => 'decimal:4',
        'period_point' => 'integer',
        'period_duration' => 'integer',
    ];
}
