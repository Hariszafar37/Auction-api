<?php

namespace App\Rules;

use App\Support\MediaUploadLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates a single vehicle media upload and explains any failure in language
 * a yard operator can act on.
 *
 * This replaces the stock `file|mimetypes:…|max:…` chain, which produced
 * messages like "The files.0 failed to upload." — text that names neither the
 * file nor the real reason. Worse, that particular message appeared when PHP
 * had silently discarded an oversized file, so the only visible symptom of a
 * server misconfiguration was a validation error blaming the user's data.
 *
 * Every message here names the offending file and states the actual number it
 * broke, so the next person to hit a limit knows which limit and by how much.
 */
class VehicleMediaFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('One of the selected items is not a file. Please choose photos or videos only.');

            return;
        }

        $name = trim($value->getClientOriginalName()) ?: 'This file';

        // PHP rejected the upload before Laravel ever saw usable bytes. The file
        // is empty at this point, so its real size is unknowable — report the
        // limit that rejected it instead of a size we would have to invent.
        if (! $value->isValid()) {
            $fail($this->uploadErrorMessage($name, $value->getError()));

            return;
        }

        // Content sniff via fileinfo, not the browser-supplied Content-Type.
        $mime = $value->getMimeType() ?? '';

        if (! MediaUploadLimits::isAllowed($mime)) {
            $fail(sprintf(
                '%s is not a supported format. Please upload %s.',
                $name,
                MediaUploadLimits::ACCEPTED_LABEL,
            ));

            return;
        }

        $maxKb = MediaUploadLimits::maxKbFor($mime);

        if ($value->getSize() > $maxKb * 1024) {
            $fail(sprintf(
                '%s is %s — %ss must be under %s.',
                $name,
                MediaUploadLimits::formatBytes((int) $value->getSize()),
                MediaUploadLimits::nounFor($mime),
                MediaUploadLimits::formatKb($maxKb),
            ));
        }
    }

    /**
     * Translate a PHP upload error constant into something actionable.
     *
     * UPLOAD_ERR_INI_SIZE is the one that matters: it means php.ini's
     * upload_max_filesize is lower than the file, which is a server
     * configuration problem, not a user mistake. The message says so.
     */
    private function uploadErrorMessage(string $name, int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                '%s is larger than this server currently accepts (%s per file). '
                . 'Ask an administrator to raise the upload limit.',
                $name,
                MediaUploadLimits::serverUploadLimit(),
            ),
            UPLOAD_ERR_PARTIAL => sprintf(
                '%s only uploaded part way. Check your connection and try again.',
                $name,
            ),
            UPLOAD_ERR_NO_FILE => 'No file was received. Please select a file and try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => sprintf(
                '%s could not be saved because of a server problem. Please try again, '
                . 'or contact support if it keeps happening.',
                $name,
            ),
            default => sprintf('%s could not be uploaded. Please try again.', $name),
        };
    }
}
