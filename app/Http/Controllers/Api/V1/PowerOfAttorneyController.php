<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\EsignPoaRequest;
use App\Http\Requests\Activation\UploadPoaRequest;
use App\Models\PowerOfAttorney;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'disk'      => $disk,
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

    /**
     * GET /api/v1/poa/{poa}/download
     *
     * Stream a POA upload file.
     *
     * AUTHORIZATION: see App\Support\SignedFileUrl for the full security model.
     * Protected by three layers identical to UserDocumentController@download:
     *   1. `signed` middleware (HMAC over URL + query string)
     *   2. `throttle` middleware (leak blast-radius cap)
     *   3. PowerOfAttorneyPolicy re-run against the embedded viewer_id on
     *      every request, so revoked permissions invalidate outstanding URLs.
     */
    public function download(Request $request, PowerOfAttorney $poa): StreamedResponse
    {
        // Layer 3: re-verify the embedded viewer's policy. viewer_id tampering
        // would have failed the signed middleware already.
        $viewerId = (int) $request->query('viewer_id', 0);
        $viewer   = $viewerId > 0 ? User::find($viewerId) : null;

        if (! $viewer) {
            abort(403, 'Viewer identity no longer valid.');
        }

        Gate::forUser($viewer)->authorize('view', $poa);

        if (! $poa->file_path) {
            abort(404, 'POA has no file.');
        }

        $disk = $poa->disk ?: config('filesystems.default');

        if (! Storage::disk($disk)->exists($poa->file_path)) {
            abort(404, 'POA file not found.');
        }

        $downloadName = "poa-{$poa->id}.".pathinfo($poa->file_path, PATHINFO_EXTENSION);

        return Storage::disk($disk)->response($poa->file_path, $downloadName);
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
            // /my/poa is always authenticated; the user viewing their own POA
            // is the viewer. auth()->user() is safe here because this helper
            // is only called from handlers that live behind auth:sanctum.
            'file_url'             => \App\Support\SignedFileUrl::powerOfAttorney($poa, auth()->user()),
            'signer_printed_name'  => $poa->signer_printed_name,
            'esigned_at'           => $poa->esigned_at?->toIso8601String(),
            'admin_notes'          => $poa->admin_notes,
            'reviewed_by'          => $poa->reviewed_by,
            'reviewed_at'          => $poa->reviewed_at?->toIso8601String(),
            'created_at'           => $poa->created_at->toIso8601String(),
        ];
    }
}
