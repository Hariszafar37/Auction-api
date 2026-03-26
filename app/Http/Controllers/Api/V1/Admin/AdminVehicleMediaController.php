<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\ReorderVehicleMediaRequest;
use App\Http\Requests\Vehicle\UploadVehicleMediaRequest;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AdminVehicleMediaController extends Controller
{
    /**
     * POST /api/v1/admin/vehicles/{vehicle}/media
     *
     * Upload one or more image/video files to a vehicle.
     * Each file is routed to the correct collection based on MIME type.
     * Returns the vehicle's full normalised media list after upload.
     */
    public function store(UploadVehicleMediaRequest $request, Vehicle $vehicle): JsonResponse
    {
        $uploaded = [];
        $errors   = [];

        foreach ($request->file('files', []) as $file) {
            try {
                $mime       = $file->getMimeType() ?? '';
                $collection = str_starts_with($mime, 'video/') ? 'videos' : 'images';

                $media = $vehicle
                    ->addMedia($file)
                    ->usingFileName($this->sanitizeFilename($file->getClientOriginalName()))
                    ->toMediaCollection($collection);

                $uploaded[] = $this->formatMedia($media);
            } catch (\Throwable $e) {
                $errors[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
            }
        }

        if (empty($uploaded) && ! empty($errors)) {
            return $this->error('All uploads failed.', 422, 'upload_failed', ['files' => $errors]);
        }

        return response()->json([
            'success'  => true,
            'message'  => count($uploaded) . ' file(s) uploaded successfully.',
            'data'     => [
                'uploaded' => $uploaded,
                'media'    => $this->allMedia($vehicle),
            ],
            'errors'   => $errors ?: null,
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/vehicles/{vehicle}/media/{media}
     *
     * Delete a single media item.
     * Verifies the media belongs to this vehicle before deletion.
     * Returns the vehicle's updated media list.
     */
    public function destroy(Vehicle $vehicle, Media $media): JsonResponse
    {
        if ((int) $media->model_id !== $vehicle->id || $media->model_type !== Vehicle::class) {
            return $this->error('Media item does not belong to this vehicle.', 404, 'not_found');
        }

        $media->delete();

        return $this->success(
            ['media' => $this->allMedia($vehicle->fresh())],
            'Media deleted.',
        );
    }

    /**
     * PATCH /api/v1/admin/vehicles/{vehicle}/media/reorder
     *
     * Persist a new display order for the vehicle's media items.
     * The client sends the full ordered array of IDs; each ID receives an
     * order_column equal to its 1-based position in the array.
     *
     * Validates that every supplied ID belongs to this vehicle.
     */
    public function reorder(ReorderVehicleMediaRequest $request, Vehicle $vehicle): JsonResponse
    {
        $ids = $request->validated()['ids'];

        // Verify ownership — all IDs must belong to this vehicle
        $vehicleMediaIds = Media::where('model_type', Vehicle::class)
            ->where('model_id', $vehicle->id)
            ->pluck('id')
            ->all();

        $foreign = array_diff($ids, $vehicleMediaIds);
        if (! empty($foreign)) {
            return $this->error(
                'One or more media IDs do not belong to this vehicle.',
                422,
                'invalid_media_ids',
            );
        }

        // Update order_column for each supplied ID
        foreach ($ids as $position => $id) {
            Media::where('id', $id)->update(['order_column' => $position + 1]);
        }

        return $this->success(
            ['media' => $this->allMedia($vehicle->fresh())],
            'Media order saved.',
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Return all media for a vehicle in the normalised frontend shape,
     * sorted by order_column ascending.
     */
    private function allMedia(Vehicle $vehicle): array
    {
        return $vehicle->getMedia('images')
            ->merge($vehicle->getMedia('videos'))
            ->sortBy('order_column')
            ->values()
            ->map(fn ($m) => $this->formatMedia($m))
            ->all();
    }

    /**
     * Normalise a single Media model into the shape expected by the frontend.
     */
    private function formatMedia(Media $media): array
    {
        return [
            'id'         => $media->id,
            'type'       => $media->collection_name === 'videos' ? 'video' : 'image',
            'url'        => $media->getUrl(),
            'thumb_url'  => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'file_name'  => $media->file_name,
            'mime_type'  => $media->mime_type,
            'collection' => $media->collection_name,
            'size'       => $media->size,
            'order'      => $media->order_column,
        ];
    }

    /**
     * Strip dangerous characters from the original filename while preserving the extension.
     */
    private function sanitizeFilename(string $name): string
    {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);

        return ($safe ?: 'file') . ($ext ? ".{$ext}" : '');
    }
}
