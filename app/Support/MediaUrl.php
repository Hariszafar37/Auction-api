<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Builds browser-loadable URLs for media items.
 *
 * On disks that support temporary URLs (e.g. S3), returns a short-lived
 * presigned URL so private objects stay accessible without making the bucket
 * public. On disks that don't (e.g. the local "public" disk used in dev/tests),
 * falls back to the plain public URL — preserving existing behaviour there.
 */
class MediaUrl
{
    /** Presigned URL lifetime in minutes. Long enough to browse a gallery, short enough to stay private. */
    public const LIFETIME_MINUTES = 30;

    /**
     * @param  string  $conversion  Conversion name (e.g. 'thumb'), or '' for the original file.
     */
    public static function temporary(Media $media, string $conversion = ''): string
    {
        // Conversions may live on a separate disk; check the one the file actually uses.
        $diskName = ($conversion !== '' && $media->conversions_disk)
            ? $media->conversions_disk
            : $media->disk;

        if (Storage::disk($diskName)->providesTemporaryUrls()) {
            return $media->getTemporaryUrl(now()->addMinutes(self::LIFETIME_MINUTES), $conversion);
        }

        return $conversion !== '' ? $media->getUrl($conversion) : $media->getUrl();
    }
}
