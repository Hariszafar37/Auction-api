<?php

namespace App\Providers;

use App\Events\Account\AccountApproved;
use App\Events\Account\AccountRejected;
use App\Events\Account\DocumentStatusUpdated;
use App\Events\Account\POAApproved;
use App\Events\Account\POARejected;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\OutbidNotification;
use App\Events\Auction\UserWonLot;
use App\Listeners\Account\SendAccountApprovedNotification;
use App\Listeners\Account\SendAccountRejectedNotification;
use App\Listeners\Account\SendDocumentStatusNotification;
use App\Listeners\Account\SendPOAApprovedNotification;
use App\Listeners\Account\SendPOARejectedNotification;
use App\Listeners\Auction\SendAuctionWonNotification;
use App\Listeners\Auction\SendBidPlacedNotification;
use App\Listeners\Auction\SendOutbidEmailNotification;
use App\Listeners\Payment\CreateInvoiceForWonLot;
use App\Models\PowerOfAttorney;
use App\Models\UserDocument;
use App\Policies\PowerOfAttorneyPolicy;
use App\Policies\UserDocumentPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
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

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // ── Account domain events ─────────────────────────────────────────────────
        Event::listen(AccountApproved::class, SendAccountApprovedNotification::class);
        Event::listen(AccountRejected::class, SendAccountRejectedNotification::class);
        Event::listen(POAApproved::class, SendPOAApprovedNotification::class);
        Event::listen(POARejected::class, SendPOARejectedNotification::class);
        Event::listen(DocumentStatusUpdated::class, SendDocumentStatusNotification::class);

        // ── Auction domain events ─────────────────────────────────────────────────
        Event::listen(OutbidNotification::class, SendOutbidEmailNotification::class);
        Event::listen(BidPlaced::class, SendBidPlacedNotification::class);
        Event::listen(UserWonLot::class, SendAuctionWonNotification::class);
        Event::listen(UserWonLot::class, CreateInvoiceForWonLot::class);
    }
}
