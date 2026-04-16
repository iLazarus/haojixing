<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupRule extends Model
{
    protected $table = 'group_rule';

    public $timestamps = false;

    protected $fillable = [
        'tg_gid',
        'app_rule_id',
        'priority',
        'stop_on_match',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'stop_on_match' => 'boolean',
        'is_active' => 'boolean',
    ];
}
