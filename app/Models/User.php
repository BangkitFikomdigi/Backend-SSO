<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Model
{
    use HasUuids;

    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'email',
        'role',
        'password_hash',
    ];

    protected $hidden = [
        'password_hash',
    ];

    /**
     * Modul-modul (aplikasi SSO) yang boleh diakses user ini.
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'user_modules', 'user_id', 'module_id')
            ->orderBy('modules.code');
    }
}
