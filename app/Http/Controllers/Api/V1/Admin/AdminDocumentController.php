<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\Account\DocumentStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDocumentStatusRequest;
use App\Models\UserDocument;
use Illuminate\Http\JsonResponse;

class AdminDocumentController extends Controller
{
    /**
     * PATCH /api/v1/admin/documents/{document}/status
     *
     * Update a document's review status.
     */
    public function updateStatus(UpdateDocumentStatusRequest $request, UserDocument $document): JsonResponse
    {
        $document->update([
            'status'      => $request->status,
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        event(new DocumentStatusUpdated($document->fresh()));

        return $this->success(
            [
                'id'          => $document->id,
                'type'        => $document->type,
                'status'      => $document->status,
                'admin_notes' => $document->admin_notes,
                'reviewed_by' => $document->reviewed_by,
                'reviewed_at' => $document->reviewed_at,
            ],
            'Document status updated.'
        );
    }
}
