<?php

namespace App\Support;

/**
 * Single source of truth for vehicle media upload limits.
 *
 * Four layers used to cap uploads independently — php.ini, nginx, the
 * validation rule and Spatie's max_file_size — and the smallest silently won.
 * In production that was php.ini's 2 MB default, which rejected every phone
 * photo while the UI advertised 50 MB. Application code now reads its limits
 * from here so the advertised and enforced values cannot drift apart again.
 *
 * The two limits this class CANNOT enforce still live on the server:
 *   php.ini  upload_max_filesize / post_max_size
 *   nginx    client_max_body_size
 * Both must be configured at or above VIDEO_MAX_KB, or files are discarded
 * before PHP runs a single line of this code. serverUploadLimit() reports what
 * php.ini is actually set to, so the resulting error message says so out loud
 * instead of blaming the user's file.
 */
final class MediaUploadLimits
{
    /** Per-file ceiling for images, in kilobytes (50 MB). */
    public const IMAGE_MAX_KB = 51200;

    /** Per-file ceiling for videos, in kilobytes (250 MB). */
    public const VIDEO_MAX_KB = 256000;

    /** Maximum files accepted in a single request. */
    public const MAX_FILES_PER_REQUEST = 20;

    /**
     * Accepted image MIME types, as reported by fileinfo's content sniff —
     * never the browser-supplied type, which is trivially spoofed.
     *
     * 'image/jpg' is not a type fileinfo emits, but it is kept for safety on
     * hosts with an unusual magic database.
     */
    public const IMAGE_MIMETYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /** Accepted video MIME types. 'video/quicktime' covers iPhone .mov files. */
    public const VIDEO_MIMETYPES = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
    ];

    /** Human-readable format list, for error messages shown to end users. */
    public const ACCEPTED_LABEL = 'JPG, PNG, WebP, MP4, MOV or WebM';

    /** @return list<string> Every accepted MIME type. */
    public static function allMimetypes(): array
    {
        return array_merge(self::IMAGE_MIMETYPES, self::VIDEO_MIMETYPES);
    }

    public static function isAllowed(string $mime): bool
    {
        return in_array($mime, self::allMimetypes(), true);
    }

    public static function isVideo(string $mime): bool
    {
        return in_array($mime, self::VIDEO_MIMETYPES, true);
    }

    /** Per-file ceiling in kilobytes for the given MIME type. */
    public static function maxKbFor(string $mime): int
    {
        return self::isVideo($mime) ? self::VIDEO_MAX_KB : self::IMAGE_MAX_KB;
    }

    /** 'photo' or 'video' — for phrasing messages in the user's language. */
    public static function nounFor(string $mime): string
    {
        return self::isVideo($mime) ? 'video' : 'photo';
    }

    /** Format a byte count as a short human string, e.g. "4.2 MB". */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    /** Format a kilobyte ceiling as a whole-number human string, e.g. "250 MB". */
    public static function formatKb(int $kb): string
    {
        return $kb >= 1024
            ? round($kb / 1024) . ' MB'
            : $kb . ' KB';
    }

    /**
     * What php.ini's upload_max_filesize is actually set to, as a human string.
     *
     * Reported in the error message when PHP discards a file for exceeding it,
     * so the limit that actually bit is named rather than guessed at.
     */
    public static function serverUploadLimit(): string
    {
        $raw = (string) ini_get('upload_max_filesize');

        if ($raw === '') {
            return 'the configured limit';
        }

        $unit  = strtoupper(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'G'     => $value . ' GB',
            'M'     => $value . ' MB',
            'K'     => self::formatKb($value),
            default => self::formatBytes((int) $raw),
        };
    }
}
