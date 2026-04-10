<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TgGroup extends Model
{
    protected $table = 'tg_group';

    public $timestamps = false;

    protected $fillable = [
        'tg_gid',
        'tg_oid',
        'is_open',
        'base_currency',
        'quote_currency',
        'exchange_rate',
        'fee_rate',
        'period_point',
        'period_duration',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'exchange_rate' => 'decimal:10',
        'fee_rate' => 'decimal:4',
        'period_point' => 'integer',
        'period_duration' => 'integer',
    ];
}
