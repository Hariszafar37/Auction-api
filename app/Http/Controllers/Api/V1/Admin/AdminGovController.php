<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateGovProfileRequest;
use App\Models\GovProfile;
use App\Models\User;
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
        $profiles = GovProfile::where('approval_status', 'pending')
            ->with('user')
            ->latest()
            ->get();

        return $this->success($profiles);
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

        return $this->success(null, 'Government account approved.');
    }

    /**
     * POST /api/v1/admin/government/{user}/reject
     */
    public function reject(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'rejection_reason' => ['required', 'string'],
        ]);

        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        $user->update(['status' => 'suspended']);

        return $this->success(null, 'Government account rejected.');
    }
}
