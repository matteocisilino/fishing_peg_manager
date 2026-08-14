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

use App\Models\SecuritySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecuritySettingController extends Controller
{
    public function edit(): View
    {
        $this->authorizeSuperAdmin();

        $settings = SecuritySetting::current();

        return view('security_settings.edit', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'require_admin_otp' => ['nullable', 'boolean'],
            'require_rilevatore_otp' => ['nullable', 'boolean'],
        ]);

        $settings = SecuritySetting::current();
        $settings->update([
            'require_admin_otp' => (bool) ($data['require_admin_otp'] ?? false),
            'require_rilevatore_otp' => (bool) ($data['require_rilevatore_otp'] ?? false),
        ]);

        return redirect()
            ->route('security-settings.edit')
            ->with('status', 'Settings updated.');
    }

    private function authorizeSuperAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasRole(\App\Models\User::ROLE_ADMIN), 403);
    }
}
