<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Vehicle;
use App\Support\MediaUploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;

/**
 * Shared media-storage loop for the admin and dealer vehicle media controllers,
 * which previously carried byte-identical copies of it.
 *
 * Storage failures used to be surfaced to the user as the raw exception text
 * (`$e->getMessage()`), which is unreadable and can leak server paths. Failures
 * are now logged with full detail and reported to the caller in plain language.
 */
trait UploadsVehicleMedia
{
    /**
     * Store each uploaded file on the vehicle, routing images and videos to
     * their own collections. One failure never discards the others.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{uploaded: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    protected function storeUploadedMedia(Vehicle $vehicle, array $files): array
    {
        $uploaded = [];
        $errors   = [];

        foreach ($files as $file) {
            $name = trim($file->getClientOriginalName()) ?: 'File';

            try {
                $mime       = $file->getMimeType() ?? '';
                $collection = MediaUploadLimits::isVideo($mime) ? 'videos' : 'images';

                $media = $vehicle
                    ->addMedia($file)
                    ->usingFileName($this->sanitizeFilename($file->getClientOriginalName()))
                    ->toMediaCollection($collection);

                $uploaded[] = $this->formatMedia($media);
            } catch (FileIsTooBig) {
                // Reachable only if media-library's max_file_size drops below the
                // ceilings in MediaUploadLimits; kept so it fails legibly if it does.
                $errors[] = sprintf(
                    '%s is too large to store. Please upload a smaller file.',
                    $name,
                );
            } catch (\Throwable $e) {
                Log::error('Vehicle media upload failed', [
                    'vehicle_id' => $vehicle->id,
                    'file'       => $name,
                    'size'       => $file->getSize(),
                    'mime'       => $file->getMimeType(),
                    'exception'  => $e,
                ]);

                $errors[] = sprintf(
                    '%s could not be saved. Please try again, or contact support if it keeps happening.',
                    $name,
                );
            }
        }

        return ['uploaded' => $uploaded, 'errors' => $errors];
    }

    /**
     * Build the response body for an upload request, reporting partial success
     * honestly: which files landed, which did not, and why.
     *
     * @param  array{uploaded: array<int, array<string, mixed>>, errors: array<int, string>}  $result
     */
    protected function uploadResultMessage(array $result, int $attempted): string
    {
        $ok     = count($result['uploaded']);
        $failed = count($result['errors']);

        if ($failed === 0) {
            return $ok === 1
                ? 'File uploaded.'
                : sprintf('%d files uploaded.', $ok);
        }

        return sprintf('%d of %d files uploaded. %s', $ok, $attempted, $result['errors'][0]);
    }
}
