<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 应用规则模型。
 *
 * 存储正则规则、执行 API 与模板映射配置。
 */
class AppRule extends Model
{
    /** @var string 对应的数据表名 */
    protected $table = 'app_rule';

    /** @var bool 手动维护 created_at/updated_at（DATE 字段） */
    public $timestamps = false;

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        // 规则备注
        'remark',
        // 规则正则表达式（PCRE）
        'regular',
        // 命中后调用的 API 地址
        'api',
        // 规则扩展映射（JSON 字符串）
        'data_map',
        // 规则是否启用
        'is_active',
        // 是否默认规则（默认规则不可删除）
        'is_default',
        // 创建日期（Asia/Shanghai，DATE）
        'created_at',
        // 更新日期（Asia/Shanghai，DATE）
        'updated_at',
    ];

    /** @var array<string, string> 字段类型转换规则 */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];
}
