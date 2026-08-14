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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::orderBy('role')->orderBy('name')->get();
        return view('users.index', [
            'users' => $users,
            'canManageAllUsers' => true,
        ]);
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'secondary_email' => ['nullable', 'email', 'max:255', 'different:email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_RILEVATORE])],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        User::create($data);

        return redirect()->route('users.index')->with('status', 'User created successfully.');
    }

    public function edit(Request $request, User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'canManageAllFields' => true,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'secondary_email' => ['nullable', 'email', 'max:255', 'different:email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_RILEVATORE])],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', $user->is_active);

        if (!empty($data['password'])) {
            $user->password = $data['password'];
        } else {
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        return redirect()->route('users.index')->with('status', 'User updated successfully.');
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['general' => 'You cannot deactivate your account.']);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return back()->with('status', 'User status updated.');
    }
}
