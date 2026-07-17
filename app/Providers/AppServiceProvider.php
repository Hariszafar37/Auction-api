<?php

namespace App\Providers;

use App\Models\PowerOfAttorney;
use App\Models\PurchaseDetail;
use App\Models\UserDocument;
use App\Policies\PowerOfAttorneyPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\UserDocumentPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Stripe live-key guard ─────────────────────────────────────────────────
        // Refuse to boot if a live Stripe key is detected outside production.
        // This is a hard stop — not a warning — so no developer can accidentally
        // run staging or local with a live key and charge real customers.
        if (! app()->environment('production')) {
            $stripeKey    = config('services.stripe.key');
            $stripeSecret = config('services.stripe.secret');

            if ($stripeKey && str_starts_with($stripeKey, 'pk_live_')) {
                throw new \RuntimeException(
                    'DANGER: Live Stripe publishable key detected in a non-production environment. ' .
                    'Set STRIPE_KEY to a test key (pk_test_...) in your .env.'
                );
            }

            if ($stripeSecret && str_starts_with($stripeSecret, 'sk_live_')) {
                throw new \RuntimeException(
                    'DANGER: Live Stripe secret key detected in a non-production environment. ' .
                    'Set STRIPE_SECRET to a test key (sk_test_...) in your .env.'
                );
            }
        }

        // ── Authorization policies ────────────────────────────────────────────────
        // Registered here (not in a dedicated AuthServiceProvider) to match the
        // existing project layout, which consolidates bindings in AppServiceProvider.
        Gate::policy(UserDocument::class, UserDocumentPolicy::class);
        Gate::policy(PowerOfAttorney::class, PowerOfAttorneyPolicy::class);
        Gate::policy(PurchaseDetail::class, PurchasePolicy::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // ── Event → listener wiring ───────────────────────────────────────────────
        // Intentionally NOT registered here. Laravel 11 auto-discovers every
        // listener in app/Listeners by scanning for a typed handle()/__invoke().
        // Registering them again with Event::listen() double-fired every domain
        // notification (e.g. a rejected document produced two bell entries).
        // Listener → event mappings now live solely in the listener signatures.
    }
}
