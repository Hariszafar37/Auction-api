<?php

namespace App\Support;

use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Centralized signed URL generator for private file downloads.
 *
 * All user-facing file downloads (user documents, POA uploads) go through
 * this helper so TTL, route naming, disk handling, and authorization stay
 * consistent. Vehicle media is intentionally public (Spatie Media Library on
 * the public disk) and does not go through here.
 *
 * ──── SECURITY MODEL ──────────────────────────────────────────────────────
 *
 * Each URL we mint carries FIVE layers of defense:
 *
 *   1. HMAC signature over the full URL (Laravel `signed` middleware).
 *      Prevents forgery by outsiders who know the document ID.
 *
 *   2. Embedded viewer_id (signed into the HMAC). Tampering with viewer_id
 *      invalidates the signature.
 *
 *   3. MINT-TIME policy check. We ask the Policy ("can this viewer view this
 *      file?") BEFORE minting a URL. If the answer is no, we return null and
 *      no URL is ever emitted. This is a defensive double-check — upstream
 *      controllers should already have authorized the caller.
 *
 *   4. DOWNLOAD-TIME policy check. The streaming controller resolves the
 *      embedded viewer from the request and re-runs the same Policy. This
 *      ensures that **permission revocation takes effect immediately** even
 *      inside the TTL window (demoted admin, deleted user, etc.).
 *
 *   5. Short TTL + rate limiting. See TTL_MINUTES below and the `throttle`
 *      middleware on the download routes.
 *
 * ──── KNOWN TRADEOFF ──────────────────────────────────────────────────────
 *
 * A signed URL that leaks within its TTL can still be followed by a stranger
 * — the backend has no browser session to bind against when a Bearer-less
 * `<a href="...">` is clicked. Layers 4/5 cap blast radius (revoke the
 * embedded viewer's permission, or throttle the endpoint). Do NOT treat these
 * URLs as suitable for long-lived publication.
 */
final class SignedFileUrl
{
    /**
     * Download URL TTL applied to every signed file route.
     * Long enough for an admin to leave a page open briefly, short enough to
     * limit exposure if a signed URL leaks into logs or referrer headers.
     */
    public const TTL_MINUTES = 30;

    /**
     * Signed URL to stream a user document (ID, licenses, etc.).
     * Returns null when:
     *   - the row has no file on disk (e-sign only / defensive), or
     *   - no viewer was supplied, or
     *   - the viewer cannot view the document per UserDocumentPolicy.
     */
    public static function userDocument(UserDocument $document, ?User $viewer): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        if (! $viewer || ! Gate::forUser($viewer)->allows('view', $document)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'documents.download',
            now()->addMinutes(self::TTL_MINUTES),
            [
                'document'  => $document->id,
                'viewer_id' => $viewer->id,
            ]
        );
    }

    /**
     * Signed URL to stream a POA upload. Same authorization model as
     * userDocument(). Returns null for e-signed POAs (no file on disk).
     */
    public static function powerOfAttorney(PowerOfAttorney $poa, ?User $viewer): ?string
    {
        if (! $poa->file_path) {
            return null;
        }

        if (! $viewer || ! Gate::forUser($viewer)->allows('view', $poa)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'poa.download',
            now()->addMinutes(self::TTL_MINUTES),
            [
                'poa'       => $poa->id,
                'viewer_id' => $viewer->id,
            ]
        );
    }
}
