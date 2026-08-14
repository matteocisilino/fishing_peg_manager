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

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Services\TrustedDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TrustedDeviceController extends Controller
{
    public function __construct(private readonly TrustedDeviceService $trustedDeviceService)
    {
    }

    public function index(): View
    {
        $user = Auth::user();
        abort_unless($user && $user->hasRole(\App\Models\User::ROLE_ADMIN), 403);

        $devices = $user->trustedDevices()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return view('trusted_devices.index', [
            'devices' => $devices,
        ]);
    }

    public function destroy(Request $request, TrustedDevice $trustedDevice): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user && $user->id === $trustedDevice->user_id, 403);

        $trustedDevice->delete();

        $response = back()->with('status', 'Device removed.');

        $cookieName = $this->trustedDeviceService->cookieName($user->id);
        $cookieValue = $request->cookie($cookieName);
        if ($cookieValue) {
            [$deviceId] = array_pad(explode('|', $cookieValue, 2), 2, null);
            if ((int) $deviceId === $trustedDevice->id) {
                $response->withCookie($this->trustedDeviceService->forgetCookie($user->id));
            }
        }

        return $response;
    }
}
