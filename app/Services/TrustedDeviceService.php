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

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class TrustedDeviceService
{
    public function cookieName(int $userId): string
    {
        return 'trusted_device_' . $userId;
    }

    /**
     * @return array{device:TrustedDevice, cookie:Cookie}
     */
    public function createTrustedDevice(User $user, Request $request, ?string $deviceName = null): array
    {
        $token = Str::random(64);
        $now = now();

        $device = $user->trustedDevices()->create([
            'device_name' => $deviceName ?: $this->defaultDeviceName($request),
            'token_hash' => Hash::make($token),
            'user_agent' => (string) $request->userAgent(),
            'ip_address' => $request->ip(),
            'last_used_at' => $now,
        ]);

        $cookie = $this->makeCookie($user->id, $device->id, $token);

        return ['device' => $device, 'cookie' => $cookie];
    }

    /**
     * @return array{device:TrustedDevice, token:string}|null
     */
    public function resolveTrustedDevice(User $user, Request $request): ?array
    {
        $cookieValue = $request->cookie($this->cookieName($user->id));
        if (!$cookieValue) {
            return null;
        }

        [$deviceId, $token] = array_pad(explode('|', $cookieValue, 2), 2, null);

        if (!$deviceId || !$token) {
            return null;
        }

        /** @var TrustedDevice|null $device */
        $device = $user->trustedDevices()->whereKey((int) $deviceId)->first();
        if (!$device || !Hash::check($token, $device->token_hash)) {
            return null;
        }

        return [
            'device' => $device,
            'token' => $token,
        ];
    }

    public function forgetCookie(int $userId): Cookie
    {
        return cookie()->forget($this->cookieName($userId));
    }

    public function makeCookie(int $userId, int $deviceId, string $token): Cookie
    {
        $value = $deviceId . '|' . $token;

        return cookie(
            $this->cookieName($userId),
            $value,
            60 * 24 * 365
        );
    }

    private function defaultDeviceName(Request $request): string
    {
        $agent = substr((string) $request->userAgent(), 0, 50);
        $date = now()->format('d/m/Y');
        return trim("Dispositivo $date $agent");
    }
}
