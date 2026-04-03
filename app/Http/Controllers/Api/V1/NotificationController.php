<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     *
     * Returns paginated database notifications for the authenticated user.
     * Unread notifications are returned first, then older ones.
     */
    public function index(Request $request): JsonResponse
    {
        $user          = $request->user();
        $perPage       = min($request->integer('per_page', 20), 50);
        $unreadOnly    = $request->boolean('unread_only', false);

        $query = $user->notifications();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate($perPage);

        return $this->success(
            $paginated->getCollection()->map(fn ($n) => $this->formatNotification($n)),
            meta: [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ]
        );
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Returns just the unread notification count.
     * Used by the frontend bell badge for polling.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     *
     * Marks a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        return $this->success($this->formatNotification($notification->fresh()), 'Notification marked as read.');
    }

    /**
     * POST /api/v1/notifications/read-all
     *
     * Marks all unread notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(null, 'All notifications marked as read.');
    }

    // ── Helper ───────────────────────────────────────────────────────────────────

    private function formatNotification(DatabaseNotification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->data['type'] ?? 'unknown',
            'title'      => $n->data['title'] ?? null,
            'message'    => $n->data['message'] ?? '',
            'action_url' => $n->data['action_url'] ?? null,
            'meta'       => $n->data['meta'] ?? [],
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
