<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserDocumentController extends Controller
{
    /**
     * GET /api/v1/documents/{document}/download
     *
     * Streams a user document file for preview/download.
     *
     * AUTHORIZATION: see App\Support\SignedFileUrl for the full security model.
     * This route sits outside `auth:sanctum` because a browser cannot attach an
     * Authorization header to an <a href="..."> click. It is protected by THREE
     * layers:
     *
     *   1. `signed` middleware validates the HMAC signature.
     *   2. `throttle` middleware rate-limits to cap abuse from leaked URLs.
     *   3. The viewer_id embedded in the signed URL is resolved here and the
     *      UserDocumentPolicy is re-run on every request, so that permission
     *      revocation (role removed, account deleted) invalidates outstanding
     *      signed URLs even within their TTL.
     */
    public function download(Request $request, UserDocument $document): StreamedResponse
    {
        // Layer 3: re-run the policy against the embedded viewer. Tampering
        // with viewer_id would have already failed the signed middleware since
        // the query string is part of the HMAC payload.
        $viewerId = (int) $request->query('viewer_id', 0);
        $viewer   = $viewerId > 0 ? User::find($viewerId) : null;

        if (! $viewer) {
            abort(403, 'Viewer identity no longer valid.');
        }

        Gate::forUser($viewer)->authorize('view', $document);

        // Stream from the disk recorded on the row, falling back to the
        // current default disk for legacy rows created before the disk column
        // existed.
        $disk = $document->disk ?: config('filesystems.default');

        if (! Storage::disk($disk)->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk($disk)->response(
            $document->file_path,
            $document->original_name
        );
    }
}
