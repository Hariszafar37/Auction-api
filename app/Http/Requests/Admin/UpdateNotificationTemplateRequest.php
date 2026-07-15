<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth + role:admin; the policy check lives there.
        return true;
    }

    /**
     * Only content and switches are writable. key / group_key / notification_type /
     * available_variables / supported_channels are code-owned: the notification
     * classes look themselves up by key and fill a fixed variable set, so letting an
     * admin edit those would break the binding between template and code.
     */
    public function rules(): array
    {
        return [
            'enabled'        => ['sometimes', 'boolean'],
            'email_enabled'  => ['sometimes', 'boolean'],
            'in_app_enabled' => ['sometimes', 'boolean'],

            'subject'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'greeting'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_body'   => ['sometimes', 'nullable', 'string', 'max:5000'],
            'action_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'title'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'message'      => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject placeholders the template cannot fill. Without this an admin could type
     * {{vehicel_name}} and ship a notification with a silently blank word in it —
     * the renderer resolves unknown variables to empty rather than failing loudly.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $template = $this->route('template');

            if (! $template) {
                return;
            }

            $allowed = $template->available_variables ?? [];

            foreach (['subject', 'greeting', 'email_body', 'action_label', 'title', 'message'] as $field) {
                $value = $this->input($field);

                if (! is_string($value) || $value === '') {
                    continue;
                }

                preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $value, $matches);

                $unknown = array_diff(array_unique($matches[1] ?? []), $allowed);

                if ($unknown !== []) {
                    $validator->errors()->add(
                        $field,
                        'Unknown placeholder(s): ' . implode(', ', array_map(
                            fn (string $v) => '{{' . $v . '}}',
                            $unknown,
                        )) . '. Available: ' . implode(', ', $allowed) . '.',
                    );
                }
            }
        });
    }
}
