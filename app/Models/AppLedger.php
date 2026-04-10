<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLedger extends Model
{
    protected $table = 'app_ledger';

    public $timestamps = false;

    protected $fillable = [
        'tg_gid',
        'tg_uid',
        'tg_belong_uid',
        'tg_msg_id',
        'is_delete',
        'amount',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_delete' => 'boolean',
        'amount' => 'integer',
    ];

    public function getAmountYuanAttribute(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }
}
