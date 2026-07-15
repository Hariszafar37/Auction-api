<?php

namespace App\Http\Requests\Admin;

use App\Support\AnnouncementAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind auth + role:admin.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],

            'email_enabled'  => ['sometimes', 'boolean'],
            'in_app_enabled' => ['sometimes', 'boolean'],

            'subject'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'greeting'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'title'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'message'    => ['sometimes', 'nullable', 'string', 'max:2000'],

            'audience'                 => ['required', 'array'],
            'audience.type'            => ['required', Rule::in(AnnouncementAudience::TYPES)],
            'audience.roles'           => ['sometimes', 'array'],
            'audience.roles.*'         => [Rule::in(AnnouncementAudience::ROLES)],
            'audience.account_types'   => ['sometimes', 'array'],
            'audience.account_types.*' => [Rule::in(AnnouncementAudience::ACCOUNT_TYPES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $audience = $this->input('audience', []);
            $type     = $audience['type'] ?? null;

            // A targeted audience with an empty selection would resolve to nobody —
            // reject it so the admin picks at least one, rather than silently
            // composing an announcement that reaches no one.
            if ($type === 'roles' && empty($audience['roles'])) {
                $validator->errors()->add('audience.roles', 'Select at least one role.');
            }

            if ($type === 'account_types' && empty($audience['account_types'])) {
                $validator->errors()->add('audience.account_types', 'Select at least one account type.');
            }

            // At least one delivery channel must be on, or sending does nothing.
            if ($this->boolean('email_enabled') === false && $this->boolean('in_app_enabled') === false) {
                $validator->errors()->add('email_enabled', 'Enable at least one channel (email or in-app).');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Default both channels on when the client omits them (create form).
        $this->mergeIfMissing([
            'email_enabled'  => true,
            'in_app_enabled' => true,
        ]);
    }
}
