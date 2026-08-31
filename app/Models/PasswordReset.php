<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordReset extends Model
{
    use HasUuids;

    protected $table = 'password_resets';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'username',
        'otp',
        'status',
        'attempts',
        'expires_at',
        'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
