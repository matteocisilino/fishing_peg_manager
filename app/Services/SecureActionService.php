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

namespace App\Services;

use App\Mail\SecureActionOtpMail;
use App\Models\SecureActionToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SecureActionService
{
    private const ACTION_GLOBAL_JOLLY = 'global_otp_jolly';

    public function issue(User $user, string $action, array $payload = [], int $ttlMinutes = 10): void
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes($ttlMinutes);

        SecureActionToken::where('user_id', $user->id)
            ->where('action', $action)
            ->delete();

        SecureActionToken::create([
            'user_id' => $user->id,
            'action' => $action,
            'code_hash' => Hash::make($code),
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ]);

        $recipient = $user->secondary_email ?: $user->email;
        Mail::to($recipient)->send(new SecureActionOtpMail($code, $expiresAt, $payload['label'] ?? ''));
    }

    public function issueGlobalJolly(User $user, int $ttlMinutes = 60): void
    {
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admins can request the joker OTP.');
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes($ttlMinutes);

        SecureActionToken::where('user_id', $user->id)
            ->where('action', self::ACTION_GLOBAL_JOLLY)
            ->delete();

        SecureActionToken::create([
            'user_id' => $user->id,
            'action' => self::ACTION_GLOBAL_JOLLY,
            'code_hash' => Hash::make($code),
            'payload' => ['label' => 'OTP jolly globale'],
            'expires_at' => $expiresAt,
        ]);

        $recipient = $user->secondary_email ?: $user->email;
        Mail::to($recipient)->send(new SecureActionOtpMail($code, $expiresAt, 'OTP jolly globale'));
    }

    public function validate(User $user, string $action, string $code): SecureActionToken
    {
        $actionToken = SecureActionToken::where('user_id', $user->id)
            ->where('action', $action)
            ->latest('id')
            ->first();

        $globalToken = $this->latestGlobalToken($user);

        if ($actionToken && $this->isTokenValid($actionToken, $code, true)) {
            return $actionToken;
        }

        if ($globalToken && $this->isTokenValid($globalToken, $code, false)) {
            return $globalToken;
        }

        throw ValidationException::withMessages([
            'otp_code' => 'Invalid or expired OTP. Please request a new one.',
        ]);
    }

    private function latestGlobalToken(User $user): ?SecureActionToken
    {
        $token = SecureActionToken::where('user_id', $user->id)
            ->where('action', self::ACTION_GLOBAL_JOLLY)
            ->latest('id')
            ->first();

        if ($token && now()->greaterThan($token->expires_at)) {
            $token->delete();
            return null;
        }

        return $token;
    }

    private function isTokenValid(SecureActionToken $token, string $code, bool $consume): bool
    {
        if (now()->greaterThan($token->expires_at)) {
            $token->delete();
            return false;
        }

        $valid = Hash::check($code, $token->code_hash);
        if ($valid && $consume) {
            $token->delete();
        }

        return $valid;
    }
}
