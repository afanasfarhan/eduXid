<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncDevice extends Model
{
    protected $fillable = [
        'school_id',
        'device_id',
        'device_type',
        'user_id',
        'device_name',
        'sync_token',
        'is_active',
        'last_synced_at',
        'sync_cursor',
    ];

    // Jangan serialisasikan bearer token yang didekripsi oleh cast Eloquent.
    protected $hidden = ['sync_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'sync_token' => 'encrypted',
        'sync_cursor' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTeachers($query)
    {
        return $query->where('device_type', 'teacher');
    }

    public function scopeStudents($query)
    {
        return $query->where('device_type', 'student');
    }

    public function markSynced(): bool
    {
        $this->last_synced_at = now();
        return $this->save();
    }

    public function activate(): bool
    {
        $this->is_active = true;
        return $this->save();
    }

    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }
}