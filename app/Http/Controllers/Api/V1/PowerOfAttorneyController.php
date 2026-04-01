<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\EsignPoaRequest;
use App\Http\Requests\Activation\UploadPoaRequest;
use App\Models\PowerOfAttorney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PowerOfAttorneyController extends Controller
{
    /**
     * POST /api/v1/activation/poa/upload
     *
     * Upload a signed POA document file.
     */
    public function upload(UploadPoaRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->hasActivePoa($user->id)) {
            return $this->error(
                'A Power of Attorney document already exists for this account.',
                422,
                'poa_already_exists'
            );
        }

        $file = $request->file('file');
        $disk = config('filesystems.default');

        $path = $file->store(
            "poa/{$user->id}",
            $disk
        );

        $poa = PowerOfAttorney::create([
            'user_id'   => $user->id,
            'type'      => 'upload',
            'status'    => 'signed',
            'file_path' => $path,
        ]);

        return $this->success($this->formatPoa($poa), 'Power of Attorney document uploaded.', 201);
    }

    /**
     * POST /api/v1/activation/poa/esign
     *
     * Electronically sign a POA in-app.
     */
    public function esign(EsignPoaRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->hasActivePoa($user->id)) {
            return $this->error(
                'A Power of Attorney document already exists for this account.',
                422,
                'poa_already_exists'
            );
        }

        $poa = PowerOfAttorney::create([
            'user_id'              => $user->id,
            'type'                 => 'esign',
            'status'               => 'signed',
            'signer_printed_name'  => $request->signer_printed_name,
            'esigned_at'           => now(),
            'esign_ip_address'     => $request->ip(),
        ]);

        return $this->success($this->formatPoa($poa), 'Power of Attorney e-signed successfully.', 201);
    }

    /**
     * GET /api/v1/my/poa
     *
     * Return current user's POA record or null.
     */
    public function show(Request $request): JsonResponse
    {
        $poa = PowerOfAttorney::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        return $this->success($poa ? $this->formatPoa($poa) : null);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function hasActivePoa(int $userId): bool
    {
        return PowerOfAttorney::where('user_id', $userId)
            ->whereIn('status', ['signed', 'approved'])
            ->exists();
    }

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
