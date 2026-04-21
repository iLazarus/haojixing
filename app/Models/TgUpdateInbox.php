<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TgUpdateInbox extends Model
{
    protected $table = 'tg_update_inbox';

    protected $fillable = [
        'update_id',
        'update_type',
        'chat_id',
        'message_id',
        'message_text',
        'payload',
        'status',
        'result_code',
        'process_detail',
        'attempt_count',
        'last_error',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'update_id' => 'integer',
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'attempt_count' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
