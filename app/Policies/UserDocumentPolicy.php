<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserDocument;

/**
 * Authorization rules for sensitive user-uploaded documents (ID, licenses,
 * bill of sale, etc.). Used by both:
 *   - App\Support\SignedFileUrl (mint-time defense-in-depth)
 *   - App\Http\Controllers\Api\V1\UserDocumentController (download-time
 *     re-verification, so revoked permissions take effect immediately)
 */
class UserDocumentPolicy
{
    /**
     * A user may view a document iff they are:
     *   1. the uploader (their own KYC file), or
     *   2. an admin (reviewing for compliance).
     *
     * Deliberately does NOT grant access to "staff" or any other role.
     * Expand explicitly here if a new role needs read access — do not widen
     * via middleware-only changes elsewhere.
     */
    public function view(User $user, UserDocument $document): bool
    {
        if ($user->id === $document->user_id) {
            return true;
        }

        return $user->hasRole('admin');
    }
}
