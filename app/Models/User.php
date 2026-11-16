<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;
    use SoftDeletes;

    /** TRUST-BOUNDARY: Privileges and mandatory password changes require explicit server assignment. */
    protected $fillable = ['username', 'name', 'password'];

    /** SECURITY: Exclude password hashes and remember tokens from serialized account data. */
    protected $hidden = ['password', 'remember_token'];

    /**
     * SECURITY: Hash credentials at assignment and compare role flags as booleans.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }
}
