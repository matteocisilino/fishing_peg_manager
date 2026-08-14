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

use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class OtpController extends Controller
{
    public function __construct(private readonly TrustedDeviceService $trustedDeviceService)
    {
    }

    public function show(): View|RedirectResponse
    {
        if (!session()->has('otp_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.otp');
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
            'remember_device' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $userId = $request->session()->get('otp_user_id');
        if (!$userId) {
            return redirect()->route('login')->withErrors(['general' => 'OTP session expired.']);
        }

        /** @var User|null $user */
        $user = User::find($userId);
        if (!$user || !$user->otp_code || !$user->otp_expires_at || now()->greaterThan($user->otp_expires_at)) {
            $request->session()->forget(['otp_user_id', 'otp_remember']);
            return redirect()->route('login')->withErrors(['general' => 'OTP expired or invalid.']);
        }

        if (!Hash::check($request->input('code'), $user->otp_code)) {
            return back()->withErrors(['code' => 'Invalid OTP code.']);
        }

        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        $remember = $request->session()->pull('otp_remember', false);
        $request->session()->forget('otp_user_id');

        Auth::login($user, $remember);

        $cookie = null;
        if ($request->boolean('remember_device')) {
            $deviceName = $validated['device_name'] ?? null;
            $result = $this->trustedDeviceService->createTrustedDevice($user, $request, $deviceName);
            $cookie = $result['cookie'];
        }

        $response = redirect()->intended(route('dashboard'))->with('status', 'Login successful.');

        if ($cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
