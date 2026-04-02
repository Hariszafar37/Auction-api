<?php

use App\Http\Controllers\Api\V1\Activation\ActivationController;
use App\Http\Controllers\Api\V1\Admin\AdminAuctionController;
use App\Http\Controllers\Api\V1\Admin\AdminAuctionLotController;
use App\Http\Controllers\Api\V1\Admin\AdminDocumentController;
use App\Http\Controllers\Api\V1\Admin\AdminGovController;
use App\Http\Controllers\Api\V1\Admin\AdminPoaController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleMediaController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auction\AuctionController;
use App\Http\Controllers\Api\V1\Auction\BidController;
use App\Http\Controllers\Api\V1\PowerOfAttorneyController;
use App\Http\Controllers\Api\V1\Vehicle\VehicleController;
use App\Http\Controllers\Api\V1\Dealer\DealerVehicleController;
use App\Http\Controllers\Api\V1\Dealer\DealerVehicleMediaController;
use App\Http\Controllers\Api\V1\Seller\SellerApplicationController;
use App\Http\Controllers\Api\V1\User\ProfileController;
use App\Http\Controllers\Api\V1\User\WonLotsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    */
    Route::get('/health', function () {
        return response()->json([
            'status'      => 'ok',
            'environment' => app()->environment(),
            'version'     => 'v1',
            'timestamp'   => now()->toIso8601String(),
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Public Auth Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/register',            [AuthController::class, 'register'])->name('register');
        Route::post('/login',               [AuthController::class, 'login'])->name('login');
        Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->name('verification.resend');
        Route::post('/set-password',        [AuthController::class, 'setPassword'])->name('set-password');
        Route::post('/password/forgot',     [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('/password/reset',      [AuthController::class, 'resetPassword'])->name('password.reset');
    });

    // Signed verification URL — must not be nested inside auth. prefix (Laravel email verification requirement)
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me',      [AuthController::class, 'me'])->name('me');
        });

        // Profile
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/',        [ProfileController::class, 'show'])->name('show');
            Route::patch('/',      [ProfileController::class, 'update'])->name('update');
            Route::put('/payment', [ProfileController::class, 'updatePayment'])->name('payment');
        });

        /*
        |----------------------------------------------------------------------
        | Activation Wizard (step-by-step)
        |----------------------------------------------------------------------
        */
        Route::prefix('activation')->name('activation.')->group(function () {
            Route::post('/account-type',        [ActivationController::class, 'accountType'])->name('account-type');
            Route::post('/account-information', [ActivationController::class, 'accountInformation'])->name('account-information');
            Route::post('/dealer-information',   [ActivationController::class, 'dealerInformation'])->name('dealer-information');
            Route::post('/business-information', [ActivationController::class, 'businessInformation'])->name('business-information');
            Route::post('/billing-information',  [ActivationController::class, 'billingInformation'])->name('billing-information');
            Route::post('/upload-documents',    [ActivationController::class, 'uploadDocuments'])->name('upload-documents');
            Route::post('/complete',            [ActivationController::class, 'complete'])->name('complete');

            // Power of Attorney
            Route::post('/poa/upload', [PowerOfAttorneyController::class, 'upload'])->name('poa.upload');
            Route::post('/poa/esign',  [PowerOfAttorneyController::class, 'esign'])->name('poa.esign');
        });

        // My POA record
        Route::get('/my/poa', [PowerOfAttorneyController::class, 'show'])->name('my.poa');

        /*
        |----------------------------------------------------------------------
        | Public Auction Routes (auth optional but needed for bidding)
        |----------------------------------------------------------------------
        */
        Route::prefix('auctions')->name('auctions.')->group(function () {
            Route::get('/',           [AuctionController::class, 'index'])->name('index');
            Route::get('/calendar',   [AuctionController::class, 'calendar'])->name('calendar');
            Route::get('/{auction}',  [AuctionController::class, 'show'])->name('show');
            Route::get('/{auction}/lots',                   [AuctionController::class, 'lots'])->name('lots');
            Route::get('/{auction}/lots/{lot}',             [AuctionController::class, 'showLot'])->name('lots.show');

            // Bid history — public
            Route::get('/{auction}/lots/{lot}/bids',        [BidController::class, 'bidHistory'])->name('lots.bids');

            // Place bids — requires authenticated active user
            Route::post('/{auction}/lots/{lot}/bids',                   [BidController::class, 'placeBid'])->name('lots.bids.place');
            Route::post('/{auction}/lots/{lot}/proxy-bid',              [BidController::class, 'setProxyBid'])->name('lots.proxy-bid');
            Route::post('/{auction}/lots/{lot}/if-sale/increase-bid',   [BidController::class, 'increaseIfSaleBid'])->name('lots.if-sale.increase-bid');
        });

        // My bid history
        Route::get('/my/bids', [BidController::class, 'myBids'])->name('my.bids');

        // My won lots
        Route::get('/my/won', [WonLotsController::class, 'index'])->name('my.won');

        /*
        |----------------------------------------------------------------------
        | Public Vehicle Inventory
        |----------------------------------------------------------------------
        */
        // NOTE: /locations registered BEFORE /{vehicle} to avoid routing conflict.
        Route::prefix('vehicles')->name('vehicles.')->group(function () {
            Route::get('/',                  [VehicleController::class, 'index'])->name('index');
            Route::get('/locations',         [VehicleController::class, 'locations'])->name('locations');
            Route::get('/{vehicle}',         [VehicleController::class, 'show'])->name('show');
            Route::post('/{vehicle}/notify', [VehicleController::class, 'subscribe'])->name('notify');
        });

        // Seller application (individual users only — validated in controller)
        Route::prefix('my/seller-application')->name('my.seller-application.')->group(function () {
            Route::get('/',  [SellerApplicationController::class, 'show'])->name('show');
            Route::post('/', [SellerApplicationController::class, 'store'])->name('store');
        });

        // My vehicles — accessible to dealers (role:dealer) and approved individual sellers (role:seller).
        // Middleware changed from role:dealer to permission:inventory.create so both roles pass.
        // Dealers already have inventory.create — no behaviour change for them.
        // NOTE: media/reorder registered BEFORE /{media} to avoid routing conflict.
        Route::prefix('my/vehicles')->name('my.vehicles.')->middleware('permission:inventory.create')->group(function () {
            Route::get('/',                                  [DealerVehicleController::class, 'index'])->name('index');
            Route::post('/',                                 [DealerVehicleController::class, 'store'])->name('store');
            Route::get('/{vehicle}',                         [DealerVehicleController::class, 'show'])->name('show');
            Route::post('/{vehicle}/submit-to-auction',      [DealerVehicleController::class, 'submitToAuction'])->name('submit-to-auction');

            // Dealer vehicle media management
            Route::post('/{vehicle}/media',                  [DealerVehicleMediaController::class, 'store'])->name('media.store');
            Route::patch('/{vehicle}/media/reorder',         [DealerVehicleMediaController::class, 'reorder'])->name('media.reorder');
            Route::delete('/{vehicle}/media/{media}',        [DealerVehicleMediaController::class, 'destroy'])->name('media.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Admin Routes
        |----------------------------------------------------------------------
        */
        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {

            // Users
            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/',                [AdminUserController::class, 'index'])->name('index')->middleware('permission:users.view');
                Route::get('/{user}',          [AdminUserController::class, 'show'])->name('show')->middleware('permission:users.view');
                Route::patch('/{user}/status', [AdminUserController::class, 'updateStatus'])->name('status')->middleware('permission:users.manage');
                Route::patch('/{user}/role',   [AdminUserController::class, 'updateRole'])->name('role')->middleware('permission:users.manage');
            });

            // Dealers
            Route::prefix('dealers')->name('dealers.')->group(function () {
                Route::get('/pending',         [AdminUserController::class, 'pendingDealers'])->name('pending')->middleware('permission:dealers.view');
                Route::post('/{user}/approve', [AdminUserController::class, 'approveDealer'])->name('approve')->middleware('permission:dealers.approve');
                Route::post('/{user}/reject',  [AdminUserController::class, 'rejectDealer'])->name('reject')->middleware('permission:dealers.approve');
            });

            // Businesses
            Route::prefix('businesses')->name('businesses.')->group(function () {
                Route::get('/pending',         [AdminUserController::class, 'pendingBusinesses'])->name('pending')->middleware('permission:dealers.view');
                Route::post('/{user}/approve', [AdminUserController::class, 'approveBusiness'])->name('approve')->middleware('permission:dealers.approve');
                Route::post('/{user}/reject',  [AdminUserController::class, 'rejectBusiness'])->name('reject')->middleware('permission:dealers.approve');
            });

            // Individual sellers
            Route::prefix('sellers')->name('sellers.')->group(function () {
                Route::get('/pending',         [AdminUserController::class, 'pendingSellers'])->name('pending')->middleware('permission:sellers.view');
                Route::post('/{user}/approve', [AdminUserController::class, 'approveSeller'])->name('approve')->middleware('permission:sellers.approve');
                Route::post('/{user}/reject',  [AdminUserController::class, 'rejectSeller'])->name('reject')->middleware('permission:sellers.approve');
            });

            // Auctions — admin CRUD + lifecycle
            Route::prefix('auctions')->name('auctions.')->group(function () {
                Route::get('/',              [AdminAuctionController::class, 'index'])->name('index');
                Route::post('/',             [AdminAuctionController::class, 'store'])->name('store');
                Route::get('/{auction}',     [AdminAuctionController::class, 'show'])->name('show');
                Route::patch('/{auction}',   [AdminAuctionController::class, 'update'])->name('update');

                // Lifecycle transitions
                Route::post('/{auction}/publish', [AdminAuctionController::class, 'publish'])->name('publish');
                Route::post('/{auction}/start',   [AdminAuctionController::class, 'start'])->name('start');
                Route::post('/{auction}/end',     [AdminAuctionController::class, 'end'])->name('end');
                Route::post('/{auction}/cancel',  [AdminAuctionController::class, 'cancel'])->name('cancel');

                // Lot management within an auction
                Route::post('/{auction}/lots',         [AdminAuctionLotController::class, 'store'])->name('lots.store');
                Route::patch('/{auction}/lots/{lot}',  [AdminAuctionLotController::class, 'update'])->name('lots.update');
                Route::delete('/{auction}/lots/{lot}', [AdminAuctionLotController::class, 'destroy'])->name('lots.destroy');
            });

            // Documents
            Route::patch('/documents/{document}/status', [AdminDocumentController::class, 'updateStatus'])->name('documents.status');

            // Vehicles
            // NOTE: export route must be registered BEFORE /{vehicle} to avoid routing conflict.
            Route::prefix('vehicles')->name('vehicles.')->group(function () {
                Route::get('/',                    [AdminVehicleController::class, 'index'])->name('index');
                Route::post('/',                   [AdminVehicleController::class, 'store'])->name('store');
                Route::get('/export',              [AdminVehicleController::class, 'export'])->name('export');
                Route::get('/{vehicle}',           [AdminVehicleController::class, 'show'])->name('show');
                Route::patch('/{vehicle}',         [AdminVehicleController::class, 'update'])->name('update');
                Route::delete('/{vehicle}',        [AdminVehicleController::class, 'destroy'])->name('destroy');
                Route::patch('/{vehicle}/status',  [AdminVehicleController::class, 'updateStatus'])->name('status');
                Route::post('/{vehicle}/mark-title-received', [AdminVehicleController::class, 'markTitleReceived'])->name('mark-title-received');

                // Vehicle media management
                // NOTE: /reorder registered before /{media} to avoid routing conflict.
                Route::post('/{vehicle}/media',                      [AdminVehicleMediaController::class, 'store'])->name('media.store');
                Route::patch('/{vehicle}/media/reorder',             [AdminVehicleMediaController::class, 'reorder'])->name('media.reorder');
                Route::delete('/{vehicle}/media/{media}',            [AdminVehicleMediaController::class, 'destroy'])->name('media.destroy');
            });

            // POA admin review
            Route::prefix('users/{user}/poa')->name('users.poa.')->group(function () {
                Route::get('/',                   [AdminPoaController::class, 'index'])->name('index');
                Route::post('/{poa}/approve',     [AdminPoaController::class, 'approve'])->name('approve');
                Route::post('/{poa}/reject',      [AdminPoaController::class, 'reject'])->name('reject');
            });

            // Government accounts
            Route::prefix('government')->name('government.')->group(function () {
                Route::get('/pending',           [AdminGovController::class, 'pending'])->name('pending');
                Route::get('/{user}',            [AdminGovController::class, 'show'])->name('show');
                Route::post('/',                 [AdminGovController::class, 'store'])->name('store');
                Route::post('/{user}/invite',    [AdminGovController::class, 'sendInvite'])->name('invite');
                Route::post('/{user}/approve',   [AdminGovController::class, 'approve'])->name('approve');
                Route::post('/{user}/reject',    [AdminGovController::class, 'reject'])->name('reject');
            });

            // Lot-level operations (auctioneer controls)
            Route::prefix('lots')->name('lots.')->group(function () {
                Route::post('/{lot}/open',            [AdminAuctionLotController::class, 'open'])->name('open');
                Route::post('/{lot}/countdown',       [AdminAuctionLotController::class, 'startCountdown'])->name('countdown');
                Route::post('/{lot}/close',           [AdminAuctionLotController::class, 'close'])->name('close');
                Route::post('/{lot}/if-sale/approve', [AdminAuctionLotController::class, 'approveIfSale'])->name('if-sale.approve');
                Route::post('/{lot}/if-sale/reject',  [AdminAuctionLotController::class, 'rejectIfSale'])->name('if-sale.reject');
            });
        });
    });
});
