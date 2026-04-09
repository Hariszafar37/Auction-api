<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\ReorderVehicleMediaRequest;
use App\Http\Requests\Vehicle\UploadVehicleMediaRequest;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Dealer-owned vehicle media management.
 *
 * Mirrors AdminVehicleMediaController but enforces dealer ownership:
 * the authenticated user must be the vehicle's seller.
 *
 * Routes (inside middleware('role:dealer')):
 *   POST   /api/v1/my/vehicles/{vehicle}/media
 *   PATCH  /api/v1/my/vehicles/{vehicle}/media/reorder
 *   DELETE /api/v1/my/vehicles/{vehicle}/media/{media}
 */
class DealerVehicleMediaController extends Controller
{
    // ─── Actions ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/my/vehicles/{vehicle}/media
     * Upload one or more image/video files to a dealer-owned vehicle.
     */
    public function store(UploadVehicleMediaRequest $request, Vehicle $vehicle): JsonResponse
    {
        if (! $this->ownsVehicle($vehicle, $request)) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        if ($block = $this->blockIfCannotSell($request)) {
            return $block;
        }

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
            'success' => true,
            'message' => count($uploaded) . ' file(s) uploaded successfully.',
            'data'    => [
                'uploaded' => $uploaded,
                'media'    => $this->allMedia($vehicle),
            ],
            'errors'  => $errors ?: null,
        ], 201);
    }

    /**
     * DELETE /api/v1/my/vehicles/{vehicle}/media/{media}
     * Delete a single media item belonging to the dealer's vehicle.
     */
    public function destroy(Request $request, Vehicle $vehicle, Media $media): JsonResponse
    {
        if (! $this->ownsVehicle($vehicle, $request)) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        if ($block = $this->blockIfCannotSell($request)) {
            return $block;
        }

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
     * PATCH /api/v1/my/vehicles/{vehicle}/media/reorder
     * Persist a new display order for the vehicle's media items.
     */
    public function reorder(ReorderVehicleMediaRequest $request, Vehicle $vehicle): JsonResponse
    {
        if (! $this->ownsVehicle($vehicle, $request)) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        if ($block = $this->blockIfCannotSell($request)) {
            return $block;
        }

        $ids = $request->validated()['ids'];

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

        foreach ($ids as $position => $id) {
            Media::where('id', $id)->update(['order_column' => $position + 1]);
        }

        return $this->success(
            ['media' => $this->allMedia($vehicle->fresh())],
            'Media order saved.',
        );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────────

    private function ownsVehicle(Vehicle $vehicle, Request $request): bool
    {
        return $vehicle->seller_id === $request->user()->id;
    }

    /**
     * Returns a 403 response when the authenticated user cannot perform seller
     * actions right now (e.g. dealer/business at pending_approval), or null when
     * the request may proceed. Ownership is checked first so we don't leak
     * existence of vehicles the user doesn't own.
     */
    private function blockIfCannotSell(Request $request): ?JsonResponse
    {
        if ($request->user()->canPerformSellerActions()) {
            return null;
        }

        return $this->error(
            'Your account must be approved and active before performing seller actions.',
            403,
            'seller_inactive'
        );
    }

    private function allMedia(Vehicle $vehicle): array
    {
        return $vehicle->getMedia('images')
            ->merge($vehicle->getMedia('videos'))
            ->sortBy('order_column')
            ->values()
            ->map(fn ($m) => $this->formatMedia($m))
            ->all();
    }

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

    private function sanitizeFilename(string $name): string
    {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);

        return ($safe ?: 'file') . ($ext ? ".{$ext}" : '');
    }
}
