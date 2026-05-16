<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TrialController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Phase 7)
|--------------------------------------------------------------------------
|
| All routes here are auto-prefixed with /api by bootstrap/app.php.
| The api middleware group applies HandleCors + globalEdge + log.request.
|
| Per-route middleware stacks below add:
|   - require.device              (X-Client-Secret + X-Device-ID validation)
|   - throttle:<edgeLimit>        (per-IP edge cap appropriate to endpoint)
|   - check.rate:<analyze|profile> OR check.score.volume  (per-device daily cap)
|
| Phase 7 additions:
|   - /api/subscription/status              (GET, no extra rate limit)
|   - /api/subscription/restore             (DEPRECATED — HTTP 410)
|   - /api/subscription/restore/start       (credentialEdge limited)
|   - /api/subscription/restore/confirm     (credentialEdge limited)
|   - /api/trial/activate                   (credentialEdge limited)
|   - /api/device/email                     (no extra rate limit — cheap)
|
| Phase 8 will add: /webhook/lemonsqueezy (separate route file)
| Phase 9 will add: /admin/* (separate route file)
*/

// ── Health (P1, no auth) ──────────────────────────────────────────────────────
Route::get('/health', HealthController::class)->name('health');

// ── Score (P6) ────────────────────────────────────────────────────────────────
Route::post('/score/batch', ScoreController::class)
    ->middleware([
        'require.device',
        'throttle:scoreEdge',
        'check.score.volume',
    ])
    ->name('score.batch');

// ── Analyze (P6) ──────────────────────────────────────────────────────────────
Route::post('/analyze/job', AnalyzeController::class)
    ->middleware([
        'require.device',
        'throttle:aiEdge',
        'check.rate:analyze',
    ])
    ->name('analyze.job');

// ── Profile (P6) ──────────────────────────────────────────────────────────────
Route::post('/profile/parse', ProfileController::class)
    ->middleware([
        'require.device',
        'throttle:aiEdge',
        'check.rate:profile',
    ])
    ->name('profile.parse');

// ── Panel (P6) ────────────────────────────────────────────────────────────────
Route::post('/panel/open', PanelController::class)
    ->middleware([
        'require.device',
        'throttle:panelEdge',
    ])
    ->name('panel.open');

// ── Subscription (P7) ─────────────────────────────────────────────────────────
// Status: hot path, called every popup open. Only require.device — no edge
// limiter beyond the global one because the response is cheap (a few small
// queries) and legitimate use can be bursty when a popup is in focus.
Route::get('/subscription/status', [SubscriptionController::class, 'status'])
    ->middleware(['require.device'])
    ->name('subscription.status');

// Deprecated restore — returns HTTP 410 with a "please update" message.
// Kept around so old extension installs see something actionable instead
// of a 404. Will be removed once Web Store install metrics show old
// versions are below ~1%.
Route::post('/subscription/restore', [SubscriptionController::class, 'restoreDeprecated'])
    ->middleware(['require.device'])
    ->name('subscription.restore.deprecated');

// Verified restore — credential-touching, so credentialEdge limiter applies
// (8 attempts / 15min / IP). The per-email anti-spam cap is enforced in
// the controller for the start step.
Route::post('/subscription/restore/start', [SubscriptionController::class, 'restoreStart'])
    ->middleware([
        'require.device',
        'throttle:credentialEdge',
    ])
    ->name('subscription.restore.start');

Route::post('/subscription/restore/confirm', [SubscriptionController::class, 'restoreConfirm'])
    ->middleware([
        'require.device',
        'throttle:credentialEdge',
    ])
    ->name('subscription.restore.confirm');

// ── Trial (P7) ────────────────────────────────────────────────────────────────
// Credential-touching: a user activating their trial submits a real email
// that we'll dedup against. Edge limit handles enumeration / spam attempts.
Route::post('/trial/activate', TrialController::class)
    ->middleware([
        'require.device',
        'throttle:credentialEdge',
    ])
    ->name('trial.activate');

// ── Device (P7) ───────────────────────────────────────────────────────────────
// Optional email save — only require.device. Cheap endpoint, no fancy
// limits needed.
Route::post('/device/email', DeviceController::class)
    ->middleware(['require.device'])
    ->name('device.email');
