<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserDocument;
use App\Support\SignedFileUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MyDocumentController extends Controller
{
    /**
     * GET /api/v1/my/documents
     *
     * List the authenticated user's own uploaded documents with signed
     * download URLs. Open to any authenticated user (not dealer-only) because
     * individuals and businesses also upload compliance docs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $documents = UserDocument::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($doc) => [
                'id'            => $doc->id,
                'type'          => $doc->type,
                'original_name' => $doc->original_name,
                'mime_type'     => $doc->mime_type,
                'size_bytes'    => $doc->size_bytes,
                'status'        => $doc->status ?? 'pending_review',
                'admin_notes'   => $doc->admin_notes,
                'uploaded_at'   => $doc->created_at?->toISOString(),
                'reviewed_at'   => $doc->reviewed_at?->toISOString(),
                'url'           => SignedFileUrl::userDocument($doc, $user),
            ]);

        return $this->success($documents->values()->all());
    }

    /**
     * POST /api/v1/my/documents/{document}/reupload
     *
     * Replace a document file and reset its review status to pending_review.
     * Only allowed when the current status is rejected or needs_resubmission.
     */
    public function reupload(Request $request, UserDocument $document): JsonResponse
    {
        // Ownership check — user may only reupload their own documents.
        Gate::authorize('view', $document);

        if (! in_array($document->status, ['rejected', 'needs_resubmission'], true)) {
            return $this->error(
                'Only rejected or flagged documents can be resubmitted.',
                422,
                'reupload_not_allowed',
            );
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ]);

        $file = $request->file('file');
        $disk = $document->disk ?: config('filesystems.default');

        // Remove the old file before storing the replacement.
        if ($document->file_path && Storage::disk($disk)->exists($document->file_path)) {
            Storage::disk($disk)->delete($document->file_path);
        }

        $path = $file->store(
            "user-documents/{$document->user_id}/{$document->type}",
            $disk,
        );

        $document->update([
            'file_path'     => $path,
            'disk'          => $disk,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'status'        => 'pending_review',
            'admin_notes'   => null,
            'reviewed_by'   => null,
            'reviewed_at'   => null,
        ]);

        return $this->success(
            [
                'id'            => $document->id,
                'type'          => $document->type,
                'original_name' => $document->original_name,
                'status'        => $document->status,
                'url'           => SignedFileUrl::userDocument($document->fresh(), $user),
            ],
            'Document resubmitted successfully.',
        );
    }
}
