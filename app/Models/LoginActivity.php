<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoginActivity extends Model
{
    use HasUuids;

    protected $table = 'login_activities';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'username',
        'status',
        'reason',
        'ip_address',
        'user_agent',
    ];
}
