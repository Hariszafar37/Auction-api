<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Support\NotificationTemplateDefaults;
use App\Support\NotificationTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationTemplateController extends Controller
{
    /**
     * GET /admin/notification-templates
     *
     * Every system template, seeded on first read so a database that predates this
     * feature (or was never seeded) still returns the full set rather than an empty
     * list the admin cannot act on.
     */
    public function index(): JsonResponse
    {
        foreach (array_keys(NotificationTemplateDefaults::all()) as $key) {
            NotificationTemplate::forKey($key);
        }

        $templates = NotificationTemplate::query()
            ->where('category', 'system')
            ->orderBy('group_key')
            ->orderBy('key')
            ->get();

        return $this->success(NotificationTemplateResource::collection($templates));
    }

    /**
     * GET /admin/notification-templates/{template}
     */
    public function show(NotificationTemplate $template): JsonResponse
    {
        return $this->success(new NotificationTemplateResource($template->load('updatedBy')));
    }

    /**
     * PATCH /admin/notification-templates/{template}
     */
    public function update(
        UpdateNotificationTemplateRequest $request,
        NotificationTemplate $template,
    ): JsonResponse {
        $data = $request->validated();

        // Guard the toggles against the calling code's real capabilities. The UI
        // greys these out, but the API must not trust that.
        $supported = $template->supported_channels ?? [];

        if (! in_array('mail', $supported, true)) {
            unset($data['email_enabled']);
        }

        if (! in_array('database', $supported, true)) {
            unset($data['in_app_enabled']);
        }

        $template->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            new NotificationTemplateResource($template->fresh()->load('updatedBy')),
            'Notification template updated.',
        );
    }

    /**
     * POST /admin/notification-templates/{template}/preview
     *
     * Renders the submitted (unsaved) copy against sample values so an admin can see
     * the result before committing. Accepts the draft in the body rather than reading
     * the stored row — the point is to preview edits that have not been saved yet.
     */
    public function preview(Request $request, NotificationTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'subject'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'greeting'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_body'   => ['sometimes', 'nullable', 'string', 'max:5000'],
            'action_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'title'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'message'      => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $draft     = [...$template->only(array_keys($validated ?: [])), ...$validated];
        $variables = $this->sampleVariables($template->available_variables ?? []);

        return $this->success([
            'variables' => $variables,
            'email'     => [
                'subject'      => NotificationTemplateRenderer::render($draft['subject'] ?? $template->subject, $variables),
                'greeting'     => NotificationTemplateRenderer::render($draft['greeting'] ?? $template->greeting, $variables),
                'lines'        => NotificationTemplateRenderer::renderBodyLines($draft['email_body'] ?? $template->email_body, $variables),
                'action_label' => NotificationTemplateRenderer::render($draft['action_label'] ?? $template->action_label, $variables),
            ],
            'in_app'    => [
                'title'   => NotificationTemplateRenderer::render($draft['title'] ?? $template->title, $variables),
                'message' => NotificationTemplateRenderer::render($draft['message'] ?? $template->message, $variables),
            ],
        ]);
    }

    /**
     * POST /admin/notification-templates/{template}/reset
     *
     * Restore the shipped copy. Channel switches are left alone — an admin resetting
     * wording should not silently re-enable a channel they turned off.
     */
    public function reset(Request $request, NotificationTemplate $template): JsonResponse
    {
        $defaults = NotificationTemplateDefaults::get($template->key);

        if ($defaults === null) {
            return $this->error('This template has no shipped default to restore.', 422, 'no_default');
        }

        $template->update([
            'subject'      => $defaults['subject'],
            'greeting'     => $defaults['greeting'],
            'email_body'   => $defaults['email_body'],
            'action_label' => $defaults['action_label'],
            'title'        => $defaults['title'],
            'message'      => $defaults['message'],
            'updated_by'   => $request->user()->id,
        ]);

        return $this->success(
            new NotificationTemplateResource($template->fresh()->load('updatedBy')),
            'Template copy restored to the shipped default.',
        );
    }

    /**
     * Representative values so the preview reads like a real notification rather
     * than a row of placeholders.
     *
     * @param  array<int, string> $names
     * @return array<string, string>
     */
    private function sampleVariables(array $names): array
    {
        $samples = [
            'first_name'       => 'Alex',
            'app_name'         => config('app.name'),
            'vehicle_name'     => '2019 Toyota Camry',
            'lot_number'       => '42',
            'amount'           => '$12,500',
            'reason'           => 'The submitted licence had expired.',
            'admin_notes'      => 'The photo was too blurry to read.',
            'document_label'   => 'Government-Issued ID',
            'status'           => 'rejected',
            'vin'              => '4T1BF1FK5HU123456',
            'auction_title'    => 'Spring Public Auction',
            'auction_location' => 'Upper Marlboro, MD',
            'auction_date'     => 'March 14, 2026 at 10:00 AM ET',
        ];

        return array_intersect_key($samples, array_flip($names));
    }
}
