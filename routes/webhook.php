<?php

declare(strict_types=1);

use App\Http\Controllers\LemonSqueezyWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes (Phase 8)
|--------------------------------------------------------------------------
|
| Wired into bootstrap/app.php at /webhook prefix OUTSIDE the api middleware
| group. Webhook routes get NONE of:
|   - HandleCors            (it's a server-to-server call, no CORS)
|   - throttle:globalEdge   (LS may burst on retries)
|   - log.request           (controller does its own logging with mask)
|
| Signature verification is the auth mechanism. No X-Client-Secret / X-Device-ID.
|
| When configuring the webhook in LemonSqueezy dashboard:
|   URL:      https://your-domain.duckdns.org/webhook/lemonsqueezy
|   Secret:   LEMONSQUEEZY_SIGNING_SECRET (from .env)
|   Events:   subscription_created, subscription_updated, subscription_cancelled,
|             subscription_expired, subscription_payment_failed,
|             subscription_payment_refunded, subscription_resumed
*/

Route::post('/lemonsqueezy', LemonSqueezyWebhookController::class)
    ->name('webhook.lemonsqueezy');
