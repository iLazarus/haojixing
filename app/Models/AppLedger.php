<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 账本记录模型。
 *
 * 对应每一条入账/记账明细，金额以“分”为单位存储。
 */
class AppLedger extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'app_ledger';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段），不使用 Eloquent 自动时间戳 */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // Telegram 群 ID
        'tg_gid',
        // Telegram 用户 ID
        'tg_uid',
        // Telegram 用户昵称
        'tg_nickname',
        // 账单归属用户 ID
        'tg_belong_uid',
        // 账单归属用户昵称
        'tg_belong_nickname',
        // Telegram 消息 ID（用于幂等）
        'tg_msg_id',
        // 逻辑删除标记
        'is_delete',
        // 金额（单位：分）
        'amount',
        // 币种类型（R/U）
        'currency_type',
        // Telegram 群名称
        'tg_g_name',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];

    /** @var array<string, string> 字段类型转换规则 */
    protected $casts = [
        'is_delete' => 'boolean',
        'amount' => 'integer',
        'currency_type' => 'string',
    ];

    /**
     * 获取“元”为单位的金额展示值。
     */
    public function getAmountYuanAttribute(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }
}
