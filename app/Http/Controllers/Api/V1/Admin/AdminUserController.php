<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\Account\AccountApproved;
use App\Events\Account\AccountRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUpdateUserProfileRequest;
use App\Http\Requests\Admin\CreateAdminUserRequest;
use App\Http\Requests\Admin\RejectBusinessRequest;
use App\Http\Requests\Admin\RejectDealerRequest;
use App\Http\Requests\Admin\RejectSellerRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Requests\Profile\UpdateAccountInformationRequest;
use App\Http\Requests\Profile\UpdateBillingInformationRequest;
use App\Http\Requests\Profile\UpdateBusinessInformationRequest;
use App\Http\Requests\Profile\UpdateDealerInformationRequest;
use App\Http\Requests\Profile\UpdateGovernmentIdRequest;
use App\Http\Resources\Admin\AccountActionResource;
use App\Http\Resources\UserResource;
use App\Models\AccountAction;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AdminUserController extends Controller
{
    /**
     * Relations eager-loaded for the admin user-detail view so the page can
     * render every editable section (contact / billing / business / dealer)
     * exactly like the user's own "My Profile", plus approval snapshots and
     * uploaded documents.
     */
    private const DETAIL_RELATIONS = [
        'buyerProfile',
        'dealerProfile',
        'businessProfile',
        'sellerProfile',
        'accountInformation',
        'billingInformation',
        'businessInformation',
        'dealerInformation',
        'documents',
    ];

    /**
     * GET /api/v1/admin/users
     *
     * Sorting is deliberately never left to the database. This query used to
     * have no ORDER BY at all, which in practice meant MySQL returned rows in
     * ascending primary-key order — so the newest user always landed on the
     * LAST page. That is what made newly activated users look "missing" from
     * the listing while search and status filters (narrow enough to fit one
     * page) still found them. Nothing guarantees that order either: without an
     * ORDER BY the engine may use a different one per LIMIT/OFFSET window, so
     * a row can also be duplicated across pages or skipped entirely.
     *
     * `-created_at` gives the newest-first default the admin UI expects, and
     * the trailing `id` tiebreaker keeps paging stable when timestamps collide
     * (bulk imports and seeders create many users within the same second).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 20), 100));

        $users = QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::scope('role', 'role'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
            ])
            ->allowedSorts(['created_at', 'name', 'email', 'status'])
            ->defaultSort('-created_at')
            // Applied after the requested/default sort, so it acts purely as a
            // tiebreaker and never overrides an explicit ?sort=.
            ->orderByDesc('id')
            ->with(['buyerProfile', 'dealerProfile'])
            ->paginate($perPage)
            ->appends($request->query());

        return $this->success(
            UserResource::collection($users),
            meta: [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'from'         => $users->firstItem(),
                'to'           => $users->lastItem(),
            ]
        );
    }

    /**
     * POST /api/v1/admin/users
     *
     * Create a new admin or staff user directly (no activation flow required).
     */
    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name'                    => $request->name,
            'first_name'              => $request->name,
            'last_name'               => '',
            'email'                   => $request->email,
            'password'                => Hash::make($request->password),
            'account_type'            => 'individual',
            'status'                  => 'active',
            'email_verified_at'       => now(),
            'agreed_terms_at'         => now(),
            'password_set_at'         => now(),
            'activation_completed_at' => now(),
        ]);

        $user->syncRoles([$request->role]);

        return $this->success(
            new UserResource($user->fresh()),
            'User created successfully.',
            201
        );
    }

    /**
     * GET /api/v1/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        return $this->success(
            new UserResource($user->load(self::DETAIL_RELATIONS))
        );
    }

    /**
     * PATCH /api/v1/admin/users/{user}
     *
     * Admin edit of a target user's core profile fields (name / email / phones).
     */
    public function updateProfile(AdminUpdateUserProfileRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'User profile updated.'
        );
    }

    /**
     * PATCH /api/v1/admin/users/{user}/status
     *
     * Administrative account-state change: suspend / block / reactivate (and the
     * legacy pending_* values). Blocking is a hard lockout — every active Sanctum
     * token is revoked so the restriction takes effect immediately. Suspension is
     * enforced per-request by the EnsureAccountUsable middleware without deleting
     * tokens (softer, reversible). Reactivating only restores status — it does NOT
     * re-enable bidding/selling if an admin previously disabled them.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $previous  = $user->status;
        $newStatus = $request->status;

        if ($previous === $newStatus) {
            return $this->error('User is already in that status.', 422, 'no_change');
        }

        $user->update(['status' => $newStatus]);

        // Hard lockout: kill all live sessions the moment an account is blocked.
        if ($newStatus === 'blocked') {
            $user->tokens()->delete();
        }

        $this->recordAccountAction(
            $request,
            $user,
            $request->user(),
            self::statusAction($newStatus),
            $previous,
            $newStatus,
            $request->input('reason'),
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'User status updated.'
        );
    }

    /**
     * PATCH /api/v1/admin/users/{user}/bidding
     *
     * Toggle the account's bidding capability, independent of status. Enforced
     * server-side via User::canBid() on every bid (manual + proxy).
     */
    public function toggleBidding(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason'  => ['nullable', 'string', 'max:1000'],
        ]);

        $enabled = (bool) $validated['enabled'];

        if ((bool) $user->bidding_enabled === $enabled) {
            return $this->error('Bidding is already in that state.', 422, 'no_change');
        }

        $user->update(['bidding_enabled' => $enabled]);

        $this->recordAccountAction(
            $request,
            $user,
            $request->user(),
            $enabled ? AccountAction::ACTION_BIDDING_ENABLED : AccountAction::ACTION_BIDDING_DISABLED,
            $enabled ? 'disabled' : 'enabled',
            $enabled ? 'enabled' : 'disabled',
            $validated['reason'] ?? null,
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            $enabled ? 'Bidding enabled.' : 'Bidding disabled.'
        );
    }

    /**
     * PATCH /api/v1/admin/users/{user}/selling
     *
     * Toggle the account's selling capability, independent of status. Enforced
     * server-side via User::canPerformSellerActions() on every seller write.
     */
    public function toggleSelling(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason'  => ['nullable', 'string', 'max:1000'],
        ]);

        $enabled = (bool) $validated['enabled'];

        if ((bool) $user->selling_enabled === $enabled) {
            return $this->error('Selling is already in that state.', 422, 'no_change');
        }

        $user->update(['selling_enabled' => $enabled]);

        $this->recordAccountAction(
            $request,
            $user,
            $request->user(),
            $enabled ? AccountAction::ACTION_SELLING_ENABLED : AccountAction::ACTION_SELLING_DISABLED,
            $enabled ? 'disabled' : 'enabled',
            $enabled ? 'enabled' : 'disabled',
            $validated['reason'] ?? null,
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            $enabled ? 'Selling enabled.' : 'Selling disabled.'
        );
    }

    /**
     * GET /api/v1/admin/users/{user}/account-actions
     *
     * Read-only account-restriction audit trail for a single user, newest first.
     * Eager-loads the performer to avoid N+1 queries.
     */
    public function accountActions(User $user): JsonResponse
    {
        $actions = $user->accountActions()
            ->with('performer:id,name')
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->get();

        return $this->success(AccountActionResource::collection($actions));
    }

    /**
     * Map a target status to the audit action verb.
     */
    private static function statusAction(string $status): string
    {
        return match ($status) {
            'suspended' => AccountAction::ACTION_SUSPENDED,
            'blocked'   => AccountAction::ACTION_BLOCKED,
            'active'    => AccountAction::ACTION_REACTIVATED,
            default     => AccountAction::ACTION_STATUS_CHANGED,
        };
    }

    /**
     * Append an immutable audit row for an administrative account action,
     * capturing the acting admin's request context (IP + user agent) for
     * compliance and investigations.
     */
    private function recordAccountAction(
        Request $request,
        User $subject,
        User $admin,
        string $action,
        ?string $previousValue,
        ?string $newValue,
        ?string $reason,
    ): void {
        AccountAction::create([
            'subject_user_id' => $subject->id,
            'action'          => $action,
            'previous_value'  => $previousValue,
            'new_value'       => $newValue,
            'reason'          => $reason,
            'performed_by'    => $admin->id,
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'performed_at'    => now(),
        ]);
    }

    /**
     * PATCH /api/v1/admin/users/{user}/role
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->error('You cannot change your own role.', 422, 'self_demotion');
        }

        if ($user->hasRole('admin') && $request->role !== 'admin') {
            $remainingAdmins = User::role('admin')
                ->whereNotIn('id', [$user->id, $request->user()->id])
                ->count();

            if ($remainingAdmins === 0) {
                return $this->error(
                    'Cannot demote the last admin. Promote another user to admin first.',
                    422,
                    'last_admin'
                );
            }
        }

        // Seller is a higher-tier capability layered on top of buyer: a seller
        // can always also buy, but a buyer is not a seller. So promoting to
        // seller keeps the buyer role; every other role is exclusive.
        if ($request->role === 'seller') {
            $user->syncRoles(['buyer', 'seller']);
        } else {
            $user->syncRoles([$request->role]);
        }

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'User role updated.'
        );
    }

    /**
     * PUT /api/v1/admin/users/{user}/account-information
     *
     * Admin edit of a target user's personal/contact ("shipping") details.
     * Reuses the same validation as the user's own self-service edit.
     */
    public function updateAccountInformation(UpdateAccountInformationRequest $request, User $user): JsonResponse
    {
        $user->accountInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'Contact information updated.'
        );
    }

    /**
     * PUT /api/v1/admin/users/{user}/government-id
     *
     * Admin edit of a target user's government ID details (type / number /
     * issuing country / issuing state / expiry). Gated by users.manage on the
     * route, so this data is only ever readable and writable by staff.
     *
     * Update-only for the same reason as the self-service endpoint: the address
     * columns on user_account_information are NOT NULL and cannot be satisfied
     * from an ID-only payload.
     */
    public function updateGovernmentId(UpdateGovernmentIdRequest $request, User $user): JsonResponse
    {
        if (! $user->accountInformation) {
            return $this->error(
                'This user has not completed the account information step yet.',
                422,
                'account_information_missing'
            );
        }

        $user->accountInformation->update($request->validated());

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'Government ID updated.'
        );
    }

    /**
     * PUT /api/v1/admin/users/{user}/billing-information
     */
    public function updateBillingInformation(UpdateBillingInformationRequest $request, User $user): JsonResponse
    {
        $user->billingInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'Billing information updated.'
        );
    }

    /**
     * PUT /api/v1/admin/users/{user}/business-information
     *
     * Business accounts only.
     */
    public function updateBusinessInformation(UpdateBusinessInformationRequest $request, User $user): JsonResponse
    {
        if ($user->account_type !== 'business') {
            return $this->error('Business information is only available for business accounts.', 422, 'not_a_business');
        }

        $user->businessInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'Business information updated.'
        );
    }

    /**
     * PUT /api/v1/admin/users/{user}/dealer-information
     *
     * Dealer accounts only.
     */
    public function updateDealerInformation(UpdateDealerInformationRequest $request, User $user): JsonResponse
    {
        if ($user->account_type !== 'dealer') {
            return $this->error('Dealer information is only available for dealer accounts.', 422, 'not_a_dealer');
        }

        $user->dealerInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh(self::DETAIL_RELATIONS)),
            'Dealer information updated.'
        );
    }

    /**
     * GET /api/v1/admin/dealers/pending
     */
    public function pendingDealers(Request $request): JsonResponse
    {
        $dealers = QueryBuilder::for(User::class)
            ->allowedSorts(['created_at', 'name'])
            ->whereHas('dealerProfile', fn ($q) => $q->where('approval_status', 'pending'))
            ->with('dealerProfile')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            UserResource::collection($dealers),
            meta: [
                'current_page' => $dealers->currentPage(),
                'last_page'    => $dealers->lastPage(),
                'per_page'     => $dealers->perPage(),
                'total'        => $dealers->total(),
            ]
        );
    }

    /**
     * POST /api/v1/admin/dealers/{user}/approve
     */
    public function approveDealer(Request $request, User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->dealerProfile;

        if (! $profile) {
            return $this->error('User does not have a dealer profile.', 404, 'not_found');
        }

        if ($profile->approval_status === 'approved') {
            return $this->error('Dealer is already approved.', 422, 'already_approved');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by'     => $request->user()->id,
            'reviewed_at'     => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_DEALER, $profile->id, $user->id, 'approved', $previousStatus, 'approved', null, $request->user()->id);

        $user->update(['status' => 'active']);
        event(new AccountApproved($user, 'dealer'));

        return $this->success(
            new UserResource($user->fresh('dealerProfile')),
            'Dealer approved successfully.'
        );
    }

    /**
     * POST /api/v1/admin/dealers/{user}/reject
     */
    public function rejectDealer(RejectDealerRequest $request, User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->dealerProfile;

        if (! $profile) {
            return $this->error('User does not have a dealer profile.', 404, 'not_found');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_DEALER, $profile->id, $user->id, 'rejected', $previousStatus, 'rejected', $request->reason, $request->user()->id);

        $user->update(['status' => 'suspended']);
        event(new AccountRejected($user, 'dealer', $request->reason));

        return $this->success(
            new UserResource($user->fresh('dealerProfile')),
            'Dealer rejected.'
        );
    }

    /**
     * GET /api/v1/admin/businesses/pending
     */
    public function pendingBusinesses(Request $request): JsonResponse
    {
        $businesses = QueryBuilder::for(User::class)
            ->allowedSorts(['created_at', 'name'])
            ->whereHas('businessProfile', fn ($q) => $q->where('approval_status', 'pending'))
            ->with('businessProfile')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            UserResource::collection($businesses),
            meta: [
                'current_page' => $businesses->currentPage(),
                'last_page'    => $businesses->lastPage(),
                'per_page'     => $businesses->perPage(),
                'total'        => $businesses->total(),
            ]
        );
    }

    /**
     * POST /api/v1/admin/businesses/{user}/approve
     */
    public function approveBusiness(Request $request, User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->businessProfile;

        if (! $profile) {
            return $this->error('User does not have a business profile.', 404, 'not_found');
        }

        if ($profile->approval_status === 'approved') {
            return $this->error('Business is already approved.', 422, 'already_approved');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'approved',
            'rejection_reason' => null,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_BUSINESS, $profile->id, $user->id, 'approved', $previousStatus, 'approved', null, $request->user()->id);

        $user->update(['status' => 'active']);
        event(new AccountApproved($user, 'business'));

        return $this->success(
            new UserResource($user->fresh('businessProfile')),
            'Business approved successfully.'
        );
    }

    /**
     * POST /api/v1/admin/businesses/{user}/reject
     */
    public function rejectBusiness(RejectBusinessRequest $request, User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->businessProfile;

        if (! $profile) {
            return $this->error('User does not have a business profile.', 404, 'not_found');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_BUSINESS, $profile->id, $user->id, 'rejected', $previousStatus, 'rejected', $request->reason, $request->user()->id);

        $user->update(['status' => 'suspended']);
        event(new AccountRejected($user, 'business', $request->reason));

        return $this->success(
            new UserResource($user->fresh('businessProfile')),
            'Business rejected.'
        );
    }

    /**
     * GET /api/v1/admin/sellers/pending
     */
    public function pendingSellers(Request $request): JsonResponse
    {
        $sellers = QueryBuilder::for(User::class)
            ->allowedSorts(['created_at', 'name'])
            ->whereHas('sellerProfile', fn ($q) => $q->where('approval_status', 'pending'))
            ->with('sellerProfile')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            UserResource::collection($sellers),
            meta: [
                'current_page' => $sellers->currentPage(),
                'last_page'    => $sellers->lastPage(),
                'per_page'     => $sellers->perPage(),
                'total'        => $sellers->total(),
            ]
        );
    }

    /**
     * POST /api/v1/admin/sellers/{user}/approve
     *
     * Approves an individual seller application.
     * Guards: only individual account_type, must have pending seller_profile.
     * Unlike dealer/business approval, the user is already 'active' — we only
     * assign the seller role and update account_intent.
     */
    public function approveSeller(Request $request, User $user, ApprovalService $approvals): JsonResponse
    {
        // Guard: only individuals can become sellers via this flow
        if ($user->account_type !== 'individual') {
            return $this->error(
                'Only individual account holders can be approved as sellers via this flow.',
                422,
                'wrong_account_type'
            );
        }

        $profile = $user->sellerProfile;

        if (! $profile) {
            return $this->error('User does not have a seller application.', 404, 'not_found');
        }

        if ($profile->approval_status === 'approved') {
            return $this->error('Seller application is already approved.', 422, 'already_approved');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'approved',
            'rejection_reason' => null,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_SELLER, $profile->id, $user->id, 'approved', $previousStatus, 'approved', null, $request->user()->id);

        // Assign seller role while retaining buyer role
        $user->assignRole('seller');

        // Update account_intent to reflect selling capability
        $user->update(['account_intent' => 'buyer_and_seller']);
        event(new AccountApproved($user, 'seller'));

        return $this->success(
            new UserResource($user->fresh('sellerProfile')),
            'Seller approved successfully.'
        );
    }

    /**
     * POST /api/v1/admin/sellers/{user}/reject
     *
     * Rejects an individual seller application.
     * User remains active as a buyer — only seller capability is denied.
     */
    public function rejectSeller(RejectSellerRequest $request, User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->sellerProfile;

        if (! $profile) {
            return $this->error('User does not have a seller application.', 404, 'not_found');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_SELLER, $profile->id, $user->id, 'rejected', $previousStatus, 'rejected', $request->reason, $request->user()->id);

        // User remains active as a buyer — no status change
        event(new AccountRejected($user, 'seller', $request->reason));

        return $this->success(
            new UserResource($user->fresh('sellerProfile')),
            'Seller application rejected.'
        );
    }
}
