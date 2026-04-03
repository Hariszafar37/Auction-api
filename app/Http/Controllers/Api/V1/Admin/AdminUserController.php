<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\Account\AccountApproved;
use App\Events\Account\AccountRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectBusinessRequest;
use App\Http\Requests\Admin\RejectDealerRequest;
use App\Http\Requests\Admin\RejectSellerRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AdminUserController extends Controller
{
    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $users = QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::scope('role', 'role'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
            ])
            ->allowedSorts(['created_at', 'name', 'email', 'status'])
            ->with(['buyerProfile', 'dealerProfile'])
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            UserResource::collection($users),
            meta: [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ]
        );
    }

    /**
     * GET /api/v1/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        return $this->success(
            new UserResource($user->load(['buyerProfile', 'dealerProfile', 'businessProfile', 'businessInformation', 'documents']))
        );
    }

    /**
     * PATCH /api/v1/admin/users/{user}/status
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $user->update(['status' => $request->status]);

        return $this->success(
            new UserResource($user->fresh(['buyerProfile', 'dealerProfile', 'businessProfile', 'businessInformation', 'documents'])),
            'User status updated.'
        );
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

        $user->syncRoles([$request->role]);

        return $this->success(
            new UserResource($user->fresh(['buyerProfile', 'dealerProfile', 'businessProfile', 'businessInformation', 'documents'])),
            'User role updated.'
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
    public function approveDealer(Request $request, User $user): JsonResponse
    {
        $profile = $user->dealerProfile;

        if (! $profile) {
            return $this->error('User does not have a dealer profile.', 404, 'not_found');
        }

        if ($profile->approval_status === 'approved') {
            return $this->error('Dealer is already approved.', 422, 'already_approved');
        }

        $profile->update([
            'approval_status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by'     => $request->user()->id,
            'reviewed_at'     => now(),
        ]);

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
    public function rejectDealer(RejectDealerRequest $request, User $user): JsonResponse
    {
        $profile = $user->dealerProfile;

        if (! $profile) {
            return $this->error('User does not have a dealer profile.', 404, 'not_found');
        }

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

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
    public function approveBusiness(Request $request, User $user): JsonResponse
    {
        $profile = $user->businessProfile;

        if (! $profile) {
            return $this->error('User does not have a business profile.', 404, 'not_found');
        }

        if ($profile->approval_status === 'approved') {
            return $this->error('Business is already approved.', 422, 'already_approved');
        }

        $profile->update([
            'approval_status'  => 'approved',
            'rejection_reason' => null,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

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
    public function rejectBusiness(RejectBusinessRequest $request, User $user): JsonResponse
    {
        $profile = $user->businessProfile;

        if (! $profile) {
            return $this->error('User does not have a business profile.', 404, 'not_found');
        }

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

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
    public function approveSeller(Request $request, User $user): JsonResponse
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

        $profile->update([
            'approval_status'  => 'approved',
            'rejection_reason' => null,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

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
    public function rejectSeller(RejectSellerRequest $request, User $user): JsonResponse
    {
        $profile = $user->sellerProfile;

        if (! $profile) {
            return $this->error('User does not have a seller application.', 404, 'not_found');
        }

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        // User remains active as a buyer — no status change
        event(new AccountRejected($user, 'seller', $request->reason));

        return $this->success(
            new UserResource($user->fresh('sellerProfile')),
            'Seller application rejected.'
        );
    }
}
