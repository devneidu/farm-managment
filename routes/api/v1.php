<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API routes. Loaded by bootstrap/app.php under the /api/v1 prefix.
*/

Route::get('/health', HealthController::class)->name('api.v1.health');
