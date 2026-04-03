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
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
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
    }
}
