<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SyncController;

Route::post('/sync-all', [SyncController::class, 'syncAllUp'])->middleware('throttle:30,1');

