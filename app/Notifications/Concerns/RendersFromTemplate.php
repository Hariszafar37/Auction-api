<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationTemplate;
use App\Support\NotificationTemplateRenderer;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Drives a notification's channels and copy from an admin-editable
 * NotificationTemplate instead of hardcoded strings.
 *
 * A notification using this trait implements three things:
 *   templateKey()        — which template variant to render ('account_approved.dealer')
 *   templateVariables()  — the {{placeholders}} it can fill
 *   actionPayload()      — the CTA url and the in-app `meta` blob (both code-owned)
 *
 * Copy and channel switches come from the database. URLs, ids and routing stay in
 * code on purpose: an admin mistyping a link would silently break the CTA, and the
 * meta blob is consumed by the frontend.
 */
trait RendersFromTemplate
{
    private ?NotificationTemplate $templateCache = null;

    abstract protected function templateKey(): string;

    /** @return array<string, mixed> */
    abstract protected function templateVariables(mixed $notifiable): array;

    /**
     * The CTA target and the structured meta the frontend reads.
     *
     * @return array{action_url: ?string, meta: array<string, mixed>}
     */
    abstract protected function actionPayload(): array;

    protected function template(): NotificationTemplate
    {
        return $this->templateCache ??= NotificationTemplate::forKey($this->templateKey());
    }

    /**
     * Variables every template can use, merged under the notification's own.
     *
     * @return array<string, mixed>
     */
    private function baseVariables(mixed $notifiable): array
    {
        return [
            'first_name' => $notifiable?->first_name ?? $notifiable?->name ?? 'there',
            'app_name'   => config('app.name'),
        ];
    }

    /** @return array<string, mixed> */
    private function allVariables(mixed $notifiable): array
    {
        return [
            ...$this->baseVariables($notifiable),
            ...$this->templateVariables($notifiable),
        ];
    }

    /**
     * Channels come from the template. An admin who disables a type, or its email,
     * gets an empty/!reduced channel list here and Laravel sends nothing on it.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->template()->activeChannels();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $template  = $this->template();
        $variables = $this->allVariables($notifiable);
        $action    = $this->actionPayload();

        $mail = new MailMessage;

        $subject = NotificationTemplateRenderer::render($template->subject, $variables);
        if ($subject !== '') {
            $mail->subject($subject);
        }

        $greeting = NotificationTemplateRenderer::render($template->greeting, $variables);
        if ($greeting !== '') {
            $mail->greeting($greeting);
        }

        $lines = NotificationTemplateRenderer::renderBodyLines($template->email_body, $variables);

        // The CTA is injected before the closing line, matching how the hardcoded
        // messages read: body … [button] … sign-off.
        $closing = array_pop($lines);

        foreach ($lines as $line) {
            $mail->line($line);
        }

        $actionLabel = NotificationTemplateRenderer::render($template->action_label, $variables);
        if ($actionLabel !== '' && ! empty($action['action_url'])) {
            $mail->action($actionLabel, $action['action_url']);
        }

        if ($closing !== null && $closing !== '') {
            $mail->line($closing);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->renderDatabasePayload($notifiable);
    }

    /**
     * The rendered in-app payload. Split out from toDatabase() so a notification
     * can override toDatabase(), call this, and adjust the result — e.g. supplying
     * a fallback message when an optional variable rendered empty.
     *
     * @return array<string, mixed>
     */
    protected function renderDatabasePayload(mixed $notifiable): array
    {
        $template  = $this->template();
        $variables = $this->allVariables($notifiable);
        $action    = $this->actionPayload();

        return [
            'type'       => $template->notification_type,
            'title'      => NotificationTemplateRenderer::render($template->title, $variables),
            'message'    => NotificationTemplateRenderer::render($template->message, $variables),
            'action_url' => $action['action_url'],
            'meta'       => $action['meta'],
        ];
    }

    /**
     * BroadcastChannel calls toArray() when there is no toBroadcast(); delegate to
     * the canonical payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Without this, BroadcastNotificationCreated would overwrite our 'type' key
     * with the notification's FQCN and break the frontend's icon/colour mapping.
     */
    public function broadcastType(): string
    {
        return $this->template()->notification_type;
    }
}
