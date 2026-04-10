<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TgUser extends Model
{
    protected $table = 'tg_user';

    public $timestamps = false;

    protected $fillable = [
        'tg_uid',
        'tg_username',
        'tg_nickname',
        'created_at',
        'updated_at',
    ];
}
