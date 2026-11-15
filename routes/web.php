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

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BetaController;
use App\Http\Controllers\EventController;

use App\Http\Controllers\ParticipantDirectoryController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TrustedDeviceController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WeightController;
use App\Http\Controllers\InstallController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
Route::get('/password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
Route::post('/password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->middleware('throttle:5,1')->name('password.email');
Route::get('/password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
Route::get('/otp', [\App\Http\Controllers\OtpController::class, 'show'])->name('otp.show');
Route::post('/otp', [\App\Http\Controllers\OtpController::class, 'verify'])->middleware('throttle:10,1')->name('otp.verify');

Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'process'])->middleware('throttle:10,1')->name('install.process');

Route::get('/lang/{locale}', function (string $locale) {
    if (file_exists(base_path("lang/{$locale}.json")) || is_dir(base_path("lang/{$locale}"))) {
        session(['locale' => $locale]);
    }
    return back();
})->where('locale', '[A-Za-z_]{2,10}')->name('lang.switch');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');


    Route::get('/', function () {
        $user = request()->user();

        if ($user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN)) {
            return redirect()->route('tournaments.index');
        }

        return redirect()->route('weights.index');
    })->name('dashboard');

    Route::get('/beta', [BetaController::class, 'index'])->name('beta.index');
    Route::get('/beta/meteosat', [BetaController::class, 'meteosat'])->name('beta.meteosat');
    Route::get('/beta/meteosat/{report}', [BetaController::class, 'meteosatShow'])->name('beta.meteosat.show');
    Route::post('/beta/otp-jolly', [BetaController::class, 'issueJollyOtp'])->middleware('role:super_admin')->name('beta.otp_jolly');

    Route::middleware('role:super_admin,admin')->group(function () {
        Route::get('/updates', [\App\Http\Controllers\UpdateCheckController::class, 'index'])->name('updates.index');
        Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
        Route::get('/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
        Route::get('/tournaments/create/individual', [TournamentController::class, 'createIndividual'])->name('tournaments.create_individual');
        Route::get('/tournaments/create/pool', [TournamentController::class, 'createPool'])->name('tournaments.create_pool');
        Route::post('/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
        Route::post('/tournaments/individual', [TournamentController::class, 'storeIndividual'])->name('tournaments.store_individual');
        Route::post('/tournaments/pool', [TournamentController::class, 'storePool'])->name('tournaments.store_pool');
        Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
        Route::get('/tournaments/{tournament}/results/final', [WeightController::class, 'tournamentResults'])->name('tournaments.results_final');
        Route::get('/tournaments/{tournament}/results/final/pdf', [WeightController::class, 'tournamentResultsPdf'])->name('tournaments.results_final_pdf');
        Route::get('/tournaments/{tournament}/results/partial', [WeightController::class, 'tournamentPartialResults'])->name('tournaments.partial_results');
        Route::get('/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
        Route::put('/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
        Route::post('/tournaments/{tournament}/otp', [TournamentController::class, 'requestDeleteOtp'])->name('tournaments.otp');
        Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy'])->name('tournaments.destroy');

        Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::post('/events/{event}/draw', [EventController::class, 'generateDraw'])->name('events.draw');
        Route::post('/events/{event}/consolidate', [EventController::class, 'consolidate'])->name('events.consolidate');
        Route::post('/events/{event}/draw/manual', [EventController::class, 'updateDraws'])->name('events.draw.manual');
        Route::post('/events/{event}/otp', [EventController::class, 'requestDeleteOtp'])->name('events.otp');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('/statistics/structures/{structure}/pegs/{peg}', [StatisticsController::class, 'showPeg'])->name('statistics.pegs.show');
        Route::get('/statistics/anglers/{angler}', [StatisticsController::class, 'showAngler'])->name('statistics.anglers.show');

        Route::get('/participants-directory', [ParticipantDirectoryController::class, 'index'])->name('participants.index');
        Route::put('/participants-directory/{participant}', [ParticipantDirectoryController::class, 'update'])->name('participants.update');
        Route::get('/participants-directory/suggest', [ParticipantDirectoryController::class, 'suggest'])->name('participants.suggest');
        Route::get('/participants-directory/create', [ParticipantDirectoryController::class, 'create'])->name('participants.create');
        Route::post('/participants-directory', [ParticipantDirectoryController::class, 'store'])->name('participants.store');
        Route::post('/participants-directory/bulk-assign', [ParticipantDirectoryController::class, 'bulkAssign'])->name('participants.bulk_assign');

        Route::resource('structures', StructureController::class)->except('show');
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::post('/events/{event}/unlock', [EventController::class, 'unlock'])->name('events.unlock');
        Route::post('/events/{event}/meteosat', [EventController::class, 'fetchMeteosat'])->name('events.meteosat');
        Route::get('/trusted-devices', [TrustedDeviceController::class, 'index'])->name('trusted-devices.index');
        Route::delete('/trusted-devices/{trustedDevice}', [TrustedDeviceController::class, 'destroy'])->name('trusted-devices.destroy');
        Route::get('/security-settings', [\App\Http\Controllers\SecuritySettingController::class, 'edit'])->name('security-settings.edit');
        Route::post('/security-settings', [\App\Http\Controllers\SecuritySettingController::class, 'update'])->name('security-settings.update');
        Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');

        Route::post('/participants-directory/{participant}/anonymize', [ParticipantDirectoryController::class, 'anonymize'])->name('participants.anonymize');
        Route::post('/participants-directory/{participant}/otp', [ParticipantDirectoryController::class, 'requestOtp'])->name('participants.otp');
        Route::delete('/participants-directory/{participant}', [ParticipantDirectoryController::class, 'destroy'])->name('participants.destroy');
        Route::post('/participants-directory/merge', [ParticipantDirectoryController::class, 'merge'])->name('participants.merge');
    });

    Route::middleware('role:super_admin,admin,rilevatore')->group(function () {
        Route::get('/weights', [WeightController::class, 'index'])->name('weights.index');
        Route::get('/weights/events/{event}', [WeightController::class, 'show'])->name('weights.show');
        Route::get('/weights/events/{event}/results', [WeightController::class, 'results'])->name('weights.results');
        Route::get('/weights/events/{event}/results/pdf', [WeightController::class, 'resultsPdf'])->name('weights.results_pdf');
        Route::post('/weights/events/{event}/lock-zone', [WeightController::class, 'lockZone'])->name('weights.lock_zone');
        Route::post('/weights/events/{event}/unlock-zone', [WeightController::class, 'unlockZone'])->name('weights.unlock_zone');
        Route::post('/weights/events/{event}', [WeightController::class, 'store'])->name('weights.store');
    });
});
