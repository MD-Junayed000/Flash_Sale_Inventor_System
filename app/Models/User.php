<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Eloquent user model for the Flash Sale Inventory system.
 *
 * Added HasApiTokens (Sanctum) to support token-based authentication on the
 * v1 API.  This replaces the legacy X-User-Email header, which was trivial
 * to spoof and unsuitable for production.
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mass-assignable attributes. Tokens are managed exclusively through
     * Sanctum's createToken() / tokenable()->tokens() relation, never via
     * direct assignment, so they are intentionally NOT listed here.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Attributes hidden from serialization. Sanctum tokens are exposed only
     * via the dedicated endpoint and must never leak through the JSON dump.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casts.  We deliberately keep password as a hashed string (handled by
     * the mutator on the auth flow) and not a 'hashed' cast so that older
     * callers passing already-hashed passwords still work.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
