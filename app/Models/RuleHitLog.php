<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuleHitLog extends Model
{
    protected $table = 'rule_hit_log';

    public $timestamps = false;

    protected $fillable = [
        'tg_gid',
        'tg_msg_id',
        'app_rule_id',
        'created_at',
        'updated_at',
    ];
}
