<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\NotificationTemplate;
use App\Notifications\AnnouncementNotification;
use App\Support\AnnouncementAudience;
use App\Support\NotificationTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class AdminAnnouncementController extends Controller
{
    /** GET /admin/announcements */
    public function index(): JsonResponse
    {
        $announcements = NotificationTemplate::query()
            ->announcements()
            ->latest('id')
            ->get();

        return $this->success(AnnouncementResource::collection($announcements));
    }

    /** GET /admin/announcements/{announcement} */
    public function show(NotificationTemplate $announcement): JsonResponse
    {
        $this->assertAnnouncement($announcement);

        return $this->success(new AnnouncementResource($announcement->load('updatedBy')));
    }

    /** POST /admin/announcements — create a draft. */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = NotificationTemplate::createAnnouncement([
            ...$this->contentFrom($request),
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            new AnnouncementResource($announcement),
            'Announcement drafted.',
        );
    }

    /**
     * PATCH /admin/announcements/{announcement} — edit a draft.
     * A sent announcement is immutable; re-send it instead.
     */
    public function update(
        StoreAnnouncementRequest $request,
        NotificationTemplate $announcement,
    ): JsonResponse {
        $this->assertAnnouncement($announcement);

        if (! $announcement->isDraft()) {
            return $this->error('A sent announcement cannot be edited.', 422, 'announcement_already_sent');
        }

        $announcement->update([
            ...$this->contentFrom($request),
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            new AnnouncementResource($announcement->fresh()),
            'Announcement updated.',
        );
    }

    /** DELETE /admin/announcements/{announcement} — only drafts may be deleted. */
    public function destroy(NotificationTemplate $announcement): JsonResponse
    {
        $this->assertAnnouncement($announcement);

        if (! $announcement->isDraft()) {
            return $this->error('A sent announcement cannot be deleted.', 422, 'announcement_already_sent');
        }

        $announcement->delete();

        return $this->success(null, 'Announcement deleted.');
    }

    /**
     * POST /admin/announcements/{announcement}/send
     *
     * Dispatch the announcement to its audience. Chunked so a large audience does
     * not load every user into memory; each notification is queued.
     */
    public function send(Request $request, NotificationTemplate $announcement): JsonResponse
    {
        $this->assertAnnouncement($announcement);

        $audience = $announcement->audience ?? ['type' => 'all'];
        $notification = new AnnouncementNotification($announcement);
        $recipients = 0;

        AnnouncementAudience::query($audience)
            ->chunkById(500, function ($users) use ($notification, &$recipients) {
                Notification::send($users, $notification);
                $recipients += $users->count();
            });

        $announcement->update([
            'sent_at'    => now(),
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            new AnnouncementResource($announcement->fresh()),
            "Announcement sent to {$recipients} user(s).",
        );
    }

    /**
     * POST /admin/announcements/{announcement}/preview
     *
     * Render the submitted draft against sample values, plus the resolved audience
     * size, so an admin sees the result and the blast radius before sending.
     */
    public function preview(Request $request, NotificationTemplate $announcement): JsonResponse
    {
        $this->assertAnnouncement($announcement);

        $validated = $request->validate([
            'subject'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'greeting'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'title'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'message'    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience'   => ['sometimes', 'array'],
        ]);

        $variables = ['first_name' => 'Alex', 'app_name' => config('app.name')];
        $audience  = $validated['audience'] ?? $announcement->audience ?? ['type' => 'all'];

        return $this->success([
            'recipient_count' => AnnouncementAudience::count($audience),
            'audience_label'  => AnnouncementAudience::describe($audience),
            'email'           => [
                'subject'  => NotificationTemplateRenderer::render($validated['subject'] ?? $announcement->subject, $variables),
                'greeting' => NotificationTemplateRenderer::render($validated['greeting'] ?? $announcement->greeting, $variables),
                'lines'    => NotificationTemplateRenderer::renderBodyLines($validated['email_body'] ?? $announcement->email_body, $variables),
            ],
            'in_app'          => [
                'title'   => NotificationTemplateRenderer::render($validated['title'] ?? $announcement->title, $variables),
                'message' => NotificationTemplateRenderer::render($validated['message'] ?? $announcement->message, $variables),
            ],
        ]);
    }

    /**
     * The content columns an announcement owns. Channels are booleans; a null on a
     * text field is fine (the renderer treats it as empty).
     *
     * @return array<string, mixed>
     */
    private function contentFrom(StoreAnnouncementRequest $request): array
    {
        return [
            'name'           => $request->validated('name'),
            'description'    => $request->validated('description'),
            'email_enabled'  => $request->boolean('email_enabled'),
            'in_app_enabled' => $request->boolean('in_app_enabled'),
            'subject'        => $request->validated('subject'),
            'greeting'       => $request->validated('greeting'),
            'email_body'     => $request->validated('email_body'),
            'title'          => $request->validated('title'),
            'message'        => $request->validated('message'),
            'audience'       => $request->validated('audience'),
        ];
    }

    /** Guards the shared table: these routes must never touch a system template. */
    private function assertAnnouncement(NotificationTemplate $template): void
    {
        abort_unless($template->isAnnouncement(), 404);
    }
}
