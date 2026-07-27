<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\Account\DocumentStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDocumentStatusRequest;
use App\Models\UserDocument;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;

class AdminDocumentController extends Controller
{
    /**
     * PATCH /api/v1/admin/documents/{document}/status
     *
     * Update a document's review status.
     */
    public function updateStatus(
        UpdateDocumentStatusRequest $request,
        UserDocument $document,
        ApprovalService $approvals,
    ): JsonResponse {
        $previousStatus = $document->status;

        $document->update([
            'status'      => $request->status,
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // Audit the review so the notes are replayable from the Approval Dashboard
        // timeline — previously this write was invisible to the approval pipeline.
        $approvals->record(
            ApprovalService::TYPE_DOCUMENT,
            $document->id,
            $document->user_id,
            ApprovalService::ACTION_DOCUMENT_REVIEWED,
            $previousStatus,
            $document->status,
            $document->admin_notes,
            auth()->id(),
        );

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
