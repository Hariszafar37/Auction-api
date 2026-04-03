<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateGovProfileRequest;
use App\Models\GovProfile;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\GovAccountInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminGovController extends Controller
{
    /**
     * POST /api/v1/admin/government
     *
     * Create a government/charity/repo user and profile (admin only).
     */
    public function store(CreateGovProfileRequest $request): JsonResponse
    {
        [$user, $profile] = DB::transaction(function () use ($request): array {
            $user = User::create([
                'name'         => $request->point_of_contact_name,
                'first_name'   => $request->point_of_contact_name,
                'last_name'    => '',
                'email'        => $request->email,
                'account_type' => 'government',
                'status'       => 'pending',
                'password'     => null,
            ]);

            $user->assignRole('buyer');

            $profile = GovProfile::create([
                'user_id'               => $user->id,
                'entity_name'           => $request->entity_name,
                'entity_subtype'        => $request->entity_subtype,
                'department_division'   => $request->department_division,
                'point_of_contact_name' => $request->point_of_contact_name,
                'contact_title'         => $request->contact_title,
                'phone'                 => $request->phone,
                'office_phone'          => $request->office_phone,
                'address'               => $request->address,
                'city'                  => $request->city,
                'state'                 => $request->state,
                'zip'                   => $request->zip,
                'approval_status'       => 'pending',
            ]);

            return [$user, $profile];
        });

        return $this->success(
            ['user' => $user->only(['id', 'name', 'email', 'account_type', 'status']), 'gov_profile' => $profile],
            'Government account created.',
            201
        );
    }

    /**
     * POST /api/v1/admin/government/{user}/invite
     *
     * Generate and send an invitation email to the government user.
     */
    public function sendInvite(User $user): JsonResponse
    {
        $token = Str::random(64);

        $user->govProfile()->update([
            'invite_token'   => $token,
            'invite_sent_at' => now(),
        ]);

        $user->notify(new GovAccountInvite($token));

        return $this->success(null, 'Invitation sent.');
    }

    /**
     * GET /api/v1/admin/government/pending
     *
     * List government profiles pending approval.
     */
    public function pending(): JsonResponse
    {
        $users = \App\Models\User::whereHas('govProfile', fn ($q) => $q->where('approval_status', 'pending'))
            ->with('govProfile')
            ->latest()
            ->get();

        return $this->success($users->map(fn ($user) => $this->formatGovUser($user)));
    }

    /**
     * GET /api/v1/admin/government/{user}
     *
     * Return a single government account detail.
     */
    public function show(User $user): JsonResponse
    {
        if ($user->account_type !== 'government') {
            return $this->error('User is not a government account.', 404, 'not_found');
        }

        $user->load('govProfile');

        return $this->success($this->formatGovUser($user));
    }

    /**
     * POST /api/v1/admin/government/{user}/approve
     */
    public function approve(User $user): JsonResponse
    {
        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        $profile->update([
            'approval_status' => 'approved',
            'reviewed_by'     => auth()->id(),
            'reviewed_at'     => now(),
        ]);

        $user->update(['status' => 'active']);
        $user->notify(new AccountApprovedNotification('government'));

        $user->load('govProfile');
        return $this->success($this->formatGovUser($user), 'Government account approved.');
    }

    /**
     * POST /api/v1/admin/government/{user}/reject
     */
    public function reject(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'rejection_reason' => ['required_without:reason', 'nullable', 'string'],
            'reason'           => ['required_without:rejection_reason', 'nullable', 'string'],
        ]);
        $rejectionReason = $request->rejection_reason ?? $request->reason;

        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $rejectionReason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        $user->update(['status' => 'suspended']);
        $user->notify(new AccountRejectedNotification($rejectionReason, 'government'));

        $user->load('govProfile');
        return $this->success($this->formatGovUser($user), 'Government account rejected.');
    }

    private function formatGovUser(\App\Models\User $user): array
    {
        $profile = $user->govProfile;
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'account_type'     => $user->account_type,
            'created_at'       => $user->created_at->toIso8601String(),
            'government_profile' => $profile ? [
                'entity_name'           => $profile->entity_name,
                'entity_subtype'        => $profile->entity_subtype,
                'department_division'   => $profile->department_division,
                'point_of_contact_name' => $profile->point_of_contact_name,
                'contact_title'         => $profile->contact_title,
                'phone'                 => $profile->phone,
                'office_phone'          => $profile->office_phone,
                'address'               => $profile->address,
                'city'                  => $profile->city,
                'state'                 => $profile->state,
                'zip'                   => $profile->zip,
                'approval_status'       => $profile->approval_status,
                'rejection_reason'      => $profile->rejection_reason,
                'admin_notes'           => $profile->admin_notes,
                'invite_sent_at'        => $profile->invite_sent_at?->toIso8601String(),
                'invite_accepted_at'    => $profile->invite_accepted_at?->toIso8601String(),
                'reviewed_at'           => $profile->reviewed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
