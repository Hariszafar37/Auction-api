<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectBusinessRequest;
use App\Http\Requests\Admin\RejectDealerRequest;
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

        return $this->success(
            new UserResource($user->fresh('businessProfile')),
            'Business rejected.'
        );
    }
}
