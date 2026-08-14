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

use App\Models\MeteosatReport;
use App\Services\SecureActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BetaController extends Controller
{
    public function index(): View
    {
        return view('beta.index');
    }

    public function meteosat(): View
    {
        $reports = MeteosatReport::with(['event.tournament', 'structure'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('beta.meteosat', [
            'reports' => $reports,
        ]);
    }

    public function meteosatShow(MeteosatReport $report): View
    {
        $report->loadMissing(['event.tournament', 'structure']);

        return view('beta.meteosat_show', [
            'report' => $report,
        ]);
    }

    public function issueJollyOtp(SecureActionService $secureActionService): RedirectResponse
    {
        $user = request()->user();
        if (!$user?->isSuperAdmin()) {
            abort(403);
        }

        $secureActionService->issueGlobalJolly($user);

        return back()->with('status', 'Joker OTP sent via email. Valid for all OTP operations until it expires.');
    }

}
