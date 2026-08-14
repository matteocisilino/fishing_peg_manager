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

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SuperAdminOtpMail;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly TrustedDeviceService $trustedDeviceService)
    {
    }

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended(route('tournaments.index'));
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();

            if (!$user->is_active) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Your account is deactivated. Contact an administrator.',
                ]);
            }

            if ($this->shouldEnforceOtp($user)) {
                $trustedDevice = $this->trustedDeviceService->resolveTrustedDevice($user, $request);
                $cookieResponse = null;

                if ($trustedDevice) {
                    $trustedDevice['device']->update([
                        'last_used_at' => now(),
                        'ip_address' => $request->ip(),
                        'user_agent' => (string) $request->userAgent(),
                    ]);

                    return redirect()->intended(route('dashboard'));
                }

                if ($request->cookie($this->trustedDeviceService->cookieName($user->id))) {
                    $cookieResponse = $this->trustedDeviceService->forgetCookie($user->id);
                }

                $this->initiateOtpFlow($user, $request->boolean('remember'));
                $response = redirect()->route('otp.show')->with('status', 'We have sent an OTP to your email address.');
                if ($cookieResponse) {
                    $response->withCookie($cookieResponse);
                }
                return $response;
            }

            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials are not valid.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function initiateOtpFlow(User $user, bool $remember): void
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        $user->update([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => $expiresAt,
        ]);

        Auth::logout();

        session([
            'otp_user_id' => $user->id,
            'otp_remember' => $remember,
        ]);

        $recipient = $user->secondary_email ?: $user->email;
        Mail::to($recipient)->send(new SuperAdminOtpMail($code, $expiresAt));
    }

    private function shouldEnforceOtp(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $settings = SecuritySetting::current();

        if ($user->role === User::ROLE_ADMIN) {
            return (bool) $settings->require_admin_otp;
        }

        if ($user->role === User::ROLE_RILEVATORE) {
            return (bool) $settings->require_rilevatore_otp;
        }

        return false;
    }
}
