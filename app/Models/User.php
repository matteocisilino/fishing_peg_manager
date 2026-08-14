<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetLinkMail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected static array $passwordResetRecipients = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_RILEVATORE = 'rilevatore';

    protected $fillable = [
        'name',
        'email',
        'secondary_email',
        'password',
        'role',
        'is_active',
        'otp_code',
        'otp_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'otp_expires_at' => 'datetime',
        ];
    }


    public function primaryStructure(): ?Structure
    {
        return Structure::first();
    }

    public function assignedStructure(): ?Structure
    {
        return Structure::first();
    }

    public function structures()
    {
        return Structure::query();
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : func_get_args();

        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * @return HasMany<TrustedDevice>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public static function overridePasswordResetRecipient(string $email, string $recipient): void
    {
        self::$passwordResetRecipients[strtolower($email)] = $recipient;
    }

    protected static function consumePasswordResetRecipient(string $email): ?string
    {
        $key = strtolower($email);
        $recipient = self::$passwordResetRecipients[$key] ?? null;
        unset(self::$passwordResetRecipients[$key]);

        return $recipient;
    }

    public function sendPasswordResetNotification($token): void
    {
        $recipient = self::consumePasswordResetRecipient($this->email) ?? $this->email;

        Mail::mailer('smtp')
            ->to($recipient)
            ->send(new PasswordResetLinkMail($this, $token));
    }
}
