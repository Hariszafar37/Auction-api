<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PowerOfAttorney;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPoaController extends Controller
{
    /**
     * GET /api/v1/admin/users/{user}/poa
     *
     * List all POA records for a user.
     */
    public function index(User $user): JsonResponse
    {
        $poas = PowerOfAttorney::where('user_id', $user->id)->latest()->get();

        return $this->success($poas->map(fn ($p) => $this->formatPoa($p))->values());
    }

    /**
     * POST /api/v1/admin/users/{user}/poa/{poa}/approve
     */
    public function approve(User $user, PowerOfAttorney $poa): JsonResponse
    {
        $poa->update([
            'status'      => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return $this->success($this->formatPoa($poa->fresh()), 'POA approved.');
    }

    /**
     * POST /api/v1/admin/users/{user}/poa/{poa}/reject
     */
    public function reject(User $user, PowerOfAttorney $poa, Request $request): JsonResponse
    {
        $request->validate([
            'admin_notes' => ['required', 'string'],
        ]);

        $poa->update([
            'status'      => 'rejected',
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return $this->success($this->formatPoa($poa->fresh()), 'POA rejected.');
    }

    // ── Helper ───────────────────────────────────────────────────────────────────

    private function formatPoa(PowerOfAttorney $poa): array
    {
        return [
            'id'                   => $poa->id,
            'user_id'              => $poa->user_id,
            'type'                 => $poa->type,
            'status'               => $poa->status,
            'file_path'            => $poa->file_path,
            'signer_printed_name'  => $poa->signer_printed_name,
            'esigned_at'           => $poa->esigned_at?->toIso8601String(),
            'admin_notes'          => $poa->admin_notes,
            'reviewed_by'          => $poa->reviewed_by,
            'reviewed_at'          => $poa->reviewed_at?->toIso8601String(),
            'created_at'           => $poa->created_at->toIso8601String(),
        ];
    }
}
