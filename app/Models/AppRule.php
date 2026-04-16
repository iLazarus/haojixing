<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRule extends Model
{
    protected $table = 'app_rule';

    public $timestamps = false;

    protected $fillable = [
        'remark',
        'regular',
        'api',
        'data_map',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
