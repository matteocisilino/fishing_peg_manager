<?php

namespace App\Http\Controllers;

use App\Services\GitHubUpdateChecker;
use Illuminate\Http\Request;

class UpdateCheckController extends Controller
{
    public function index(Request $request, GitHubUpdateChecker $checker)
    {
        $force = $request->boolean('force');
        $status = $checker->check($force);

        if ($force) {
            session()->flash('status', __('Update check completed.'));
        }

        return view('updates.index', [
            'status' => $status,
        ]);
    }
}
