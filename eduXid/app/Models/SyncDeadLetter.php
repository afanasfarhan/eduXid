<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncDeadLetter extends Model
{
    protected $table = 'sync_dead_letters';

    protected $fillable = [
        'event_id',
        'device_id',
        'entity_type',
        'entity_id',
        'operation',
        'version',
        'payload',
        'attempts',
        'status',
        'last_reason',
        'resolved_at',
    ];

    protected $casts = [
        'version'     => 'integer',
        'attempts'    => 'integer',
        'payload'     => 'array',
        'resolved_at' => 'datetime',
    ];
}