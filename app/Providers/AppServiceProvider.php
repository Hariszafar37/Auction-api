<?php

namespace App\Providers;

use App\Models\PowerOfAttorney;
use App\Models\PurchaseDetail;
use App\Models\UserDocument;
use App\Policies\PowerOfAttorneyPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\UserDocumentPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        $this->configureRateLimiting();

        // ── Event → listener wiring ───────────────────────────────────────────────
        // Intentionally NOT registered here. Laravel 11 auto-discovers every
        // listener in app/Listeners by scanning for a typed handle()/__invoke().
        // Registering them again with Event::listen() double-fired every domain
        // notification (e.g. a rejected document produced two bell entries).
        // Listener → event mappings now live solely in the listener signatures.
    }

    /**
     * Rate limiters for the unauthenticated auth endpoints.
     *
     * Laravel's skeleton does NOT apply throttle:api by default — bootstrap/app.php
     * has to opt in — so until this was added the entire API, including register,
     * login and the two mail-sending endpoints, had no rate limit whatsoever.
     *
     * Each limiter keys on BOTH the client IP and the submitted email. The email
     * key is the load-bearing one: it is per-account, so it works correctly even
     * when many users share an outbound IP, and it caps how hard a single victim
     * can be mailed regardless of how widely the attacker distributes source IPs.
     *
     * IMPORTANT — the IP keys are only meaningful if TRUSTED_PROXIES is set on the
     * environment. Behind the ALB with it unset, Request::ip() returns the load
     * balancer's address and every client collapses into one shared bucket. The
     * per-IP ceilings below are deliberately generous so that a misconfiguration
     * degrades into "no useful IP limiting" rather than "nobody can register".
     * See docs/registration-spam-protection.md.
     */
    private function configureRateLimiting(): void
    {
        // Registration is a once-per-lifetime action for a genuine user, so even
        // these generous ceilings are orders of magnitude above real behaviour.
        RateLimiter::for('auth-register', fn (Request $request) => array_filter([
            Limit::perHour(20)->by('register:ip:' . $request->ip()),
            Limit::perDay(50)->by('register:ip-day:' . $request->ip()),
            self::byEmail($request, 'register:email:', Limit::perHour(3)),
        ]));

        // Login has to stay forgiving — a bidder fat-fingering a password during a
        // live auction must not get locked out. This only stops bulk credential
        // stuffing, not ordinary human error.
        RateLimiter::for('auth-login', fn (Request $request) => array_filter([
            Limit::perMinute(30)->by('login:ip:' . $request->ip()),
            self::byEmail($request, 'login:email:', Limit::perMinute(10)),
        ]));

        // Both of these send mail to an address chosen by the caller, which is
        // exactly the primitive the list-bombing campaign was exploiting. The
        // per-email limit is what bounds the damage to any one victim.
        RateLimiter::for('auth-mail', fn (Request $request) => array_filter([
            Limit::perHour(20)->by('authmail:ip:' . $request->ip()),
            self::byEmail($request, 'authmail:email:', Limit::perHour(5)),
        ]));
    }

    /**
     * Build an email-keyed limit, or null when no email was submitted.
     *
     * Returning null (filtered out by the caller) rather than keying on an empty
     * string matters: without it every malformed request would share a single
     * bucket, and a burst of junk would exhaust the limit for real users.
     */
    private static function byEmail(Request $request, string $prefix, Limit $limit): ?Limit
    {
        $email = $request->input('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return $limit->by($prefix . Str::lower(trim($email)));
    }
}
