<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes (Phase 9)
|--------------------------------------------------------------------------
|
| All routes prefixed with /admin by bootstrap/app.php.
| All routes wrapped in require.admin middleware (two-tier brute-force
| lockout from Phase 4, trusted-IP bypass via TRUSTED_IPS env).
| log.request applies so admin actions show up in /admin/logs for audit.
|
| Eleven endpoints, response shapes byte-identical to the Node version
| so the existing static admin panel (public/admin-panel/) works
| unchanged against this backend.
|
| Authentication:
|   The static admin panel prompts the operator for X-Admin-Secret and
|   includes it in every XHR. The require.admin middleware verifies it
|   timing-safely against ADMIN_SECRET env var and tracks failed attempts
|   per (ip, secret-prefix) for lockout.
*/

// ── Top-level metrics ─────────────────────────────────────────────────────────
Route::get('/stats',    [AdminController::class, 'stats'])    ->name('admin.stats');
Route::get('/daily',    [AdminController::class, 'daily'])    ->name('admin.daily');
Route::get('/latency',  [AdminController::class, 'latency'])  ->name('admin.latency');
Route::get('/usage',    [AdminController::class, 'usage'])    ->name('admin.usage');
Route::get('/webhooks', [AdminController::class, 'webhooks'])->name('admin.webhooks');


// ── Request logs ──────────────────────────────────────────────────────────────
Route::get('/logs',     [AdminController::class, 'logs'])     ->name('admin.logs.index');
Route::delete('/logs',  [AdminController::class, 'clearLogs'])->name('admin.logs.clear');

// ── Subscriptions ─────────────────────────────────────────────────────────────
Route::get('/subscription/stats',   [AdminController::class, 'subscriptionStats'])  ->name('admin.subscription.stats');
Route::post('/subscription/toggle', [AdminController::class, 'toggleSubscriptions'])->name('admin.subscription.toggle');
Route::get('/subscription/devices', [AdminController::class, 'subscriptionDevices'])->name('admin.subscription.devices');

// ── Per-device overrides ──────────────────────────────────────────────────────
// {id} is the device UUID. We accept any string here (no UUID-format
// validation at the route layer) because the controller does the lookup
// and returns 404 on miss — that handles both "wrong format" and "not
// found" cleanly.
Route::post('/devices/{id}/grant-pro',   [AdminController::class, 'grantProOverride']) ->name('admin.devices.grant-pro');
Route::delete('/devices/{id}/grant-pro', [AdminController::class, 'revokeProOverride'])->name('admin.devices.revoke-pro');
