<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Merepresentasikan tabel `sessions` (nama kelas dibuat "SsoSession" supaya
 * tidak bentrok dengan Illuminate\Session\SessionManager / facade Session).
 */
class SsoSession extends Model
{
    use HasUuids;

    protected $table = 'sessions';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'user_id',
        'status',
        'activation_code',
        'activation_attempts',
        'captcha_id',
        'captcha_answer',
        'refresh_token',
        'refresh_expires_at',
        'expires_at',
        'last_activity_at',
    ];

    protected $casts = [
        'refresh_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
