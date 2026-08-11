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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
     * balancer's address and every client collapses into one shared bucket —
     * which would throttle the whole site rather than one abuser.
     *
     * byIp() detects exactly that and drops the IP key rather than applying a
     * dangerous one, so a missing TRUSTED_PROXIES degrades to "email limits only"
     * instead of "the 31st login this minute gets a 429". That is a safety net,
     * not a substitute: set TRUSTED_PROXIES so the IP limits actually work.
     * See docs/registration-spam-protection.md.
     */
    private function configureRateLimiting(): void
    {
        // Registration is a once-per-lifetime action for a genuine user, so even
        // these generous ceilings are orders of magnitude above real behaviour.
        RateLimiter::for('auth-register', fn (Request $request) => array_filter([
            ...self::byIp($request, 'register:ip:', Limit::perHour(20)),
            ...self::byIp($request, 'register:ip-day:', Limit::perDay(50)),
            self::byEmail($request, 'register:email:', Limit::perHour(3)),
        ]));

        // Login has to stay forgiving — a bidder fat-fingering a password during a
        // live auction must not get locked out. This only stops bulk credential
        // stuffing, not ordinary human error.
        RateLimiter::for('auth-login', fn (Request $request) => array_filter([
            ...self::byIp($request, 'login:ip:', Limit::perMinute(30)),
            self::byEmail($request, 'login:email:', Limit::perMinute(10)),
        ]));

        // Both of these send mail to an address chosen by the caller, which is
        // exactly the primitive the list-bombing campaign was exploiting. The
        // per-email limit is what bounds the damage to any one victim.
        RateLimiter::for('auth-mail', fn (Request $request) => array_filter([
            ...self::byIp($request, 'authmail:ip:', Limit::perHour(20)),
            self::byEmail($request, 'authmail:email:', Limit::perHour(5)),
        ]));
    }

    /**
     * Build an IP-keyed limit, or nothing when the client IP cannot be trusted.
     *
     * Behind a proxy that TRUSTED_PROXIES does not cover, Request::ip() returns
     * the PROXY's address rather than the caller's. Every client on the internet
     * then shares one bucket, and a limit meant to stop a single abuser instead
     * throttles the entire site — the login limiter would cut off the 31st person
     * to sign in within a minute, which on a live auction day is a real outage.
     *
     * Detecting that and dropping the IP key is strictly better than applying it:
     * a key that identifies everyone identifies no one, so it buys no security
     * while carrying all of that risk. The per-email limits still apply, and they
     * are the load-bearing ones anyway — they are keyed on caller-supplied data,
     * are immune to proxying, and are what bound how hard any single account or
     * victim can be hit.
     *
     * This is a safety net, not the fix. Set TRUSTED_PROXIES so the IP limits do
     * their job; see docs/registration-spam-protection.md.
     *
     * @return list<Limit> exactly one limit, or none
     */
    private static function byIp(Request $request, string $prefix, Limit $limit): array
    {
        if (! self::clientIpIsTrustworthy($request)) {
            self::warnAboutUntrustedProxy($request);

            return [];
        }

        return [$limit->by($prefix . $request->ip())];
    }

    /**
     * Whether Request::ip() actually identifies the caller.
     *
     * A forwarding header we are not configured to trust means the address we
     * can see belongs to the proxy. With no forwarding header at all, we are
     * talking to the client directly and the address is genuine.
     */
    private static function clientIpIsTrustworthy(Request $request): bool
    {
        $forwardingHeaders = ['X-Forwarded-For', 'Forwarded', 'X-Real-IP'];

        foreach ($forwardingHeaders as $header) {
            if ($request->headers->has($header) && ! $request->isFromTrustedProxy()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Surface the misconfiguration without flooding the log.
     *
     * Cache::add is atomic and only succeeds when the key is absent, so exactly
     * one warning is written per hour however many requests arrive. Silent
     * degradation would be worse than the bug: rate limiting would look healthy
     * while doing nothing.
     */
    private static function warnAboutUntrustedProxy(Request $request): void
    {
        if (! Cache::add('ratelimit:untrusted-proxy-warned', true, now()->addHour())) {
            return;
        }

        Log::warning(
            'Rate limiting: per-IP limits disabled — requests arrive via a proxy that TRUSTED_PROXIES does not cover, '
            . 'so Request::ip() reports the proxy rather than the client. Per-email limits remain active. '
            . 'Set TRUSTED_PROXIES to restore per-IP limiting.',
            [
                'reported_ip' => $request->ip(),
                'forwarded_for' => $request->headers->get('X-Forwarded-For'),
            ],
        );
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
