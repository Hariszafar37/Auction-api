<?php

namespace App\Http\Requests\Auth;

use App\Rules\HumanName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'email'                    => ['required', 'email', 'max:255', 'unique:users,email'],
            'email_confirmation'       => ['required', 'same:email'],
            'first_name'               => ['required', 'string', 'max:100', new HumanName],
            'middle_name'              => ['nullable', 'string', 'max:100', new HumanName],
            'last_name'                => ['required', 'string', 'max:100', new HumanName],
            'primary_phone'            => ['required', 'string', 'regex:/^[\+]?[\d\s\-\(\)]{7,30}$/', 'max:30'],
            'secondary_phone'          => ['nullable', 'string', 'regex:/^[\+]?[\d\s\-\(\)]{7,30}$/', 'max:30'],
            'consent_marketing'        => ['nullable', 'boolean'],
            'agree_terms'              => ['required', 'accepted'],
            'agree_ecomm_consent'      => ['required', 'accepted'],
            'agree_accuracy_confirmed' => ['required', 'accepted'],
        ], $this->botGuardRules());
    }

    /**
     * Proof that this submission came from our rendered form rather than a
     * script POSTing the API host directly.
     *
     * See config/bot_guard.php for the reasoning behind requiring PRESENCE
     * rather than the conventional "reject if the decoy was filled".
     */
    private function botGuardRules(): array
    {
        if (! config('bot_guard.enabled', true)) {
            return [];
        }

        $honeypot = $this->honeypotField();

        // 'present' demands the key exists but permits it to be empty/null.
        // 'sometimes' degrades to a conventional honeypot for the deploy window
        // where the frontend has not shipped the fields yet.
        $presence = config('bot_guard.form_signal.require_presence', true) ? 'present' : 'sometimes';

        $minMs = config('bot_guard.form_signal.min_fill_seconds', 3) * 1000;
        $maxMs = config('bot_guard.form_signal.max_form_age_seconds', 86400) * 1000;

        $strict = $presence === 'present';

        return [
            // Hidden decoy: must arrive, and must arrive empty. `nullable` is
            // correct here — an empty decoy is the whole point.
            $honeypot => [$presence, 'nullable', 'max:0'],

            // Milliseconds between form render and submit, measured entirely on
            // the client's own clock. Sent as an ELAPSED figure rather than an
            // absolute timestamp so that a skewed device clock — which is common
            // and not the user's fault — can never cause a false rejection.
            //
            // NOT nullable when strict: 'present' + 'nullable' would accept an
            // explicit null and skip the min/max entirely, handing back the very
            // bypass this field exists to close.
            'form_elapsed_ms' => $strict
                ? ['required', 'integer', "min:{$minMs}", "max:{$maxMs}"]
                : ['sometimes', 'nullable', 'integer', "min:{$minMs}", "max:{$maxMs}"],
        ];
    }

    private function honeypotField(): string
    {
        return (string) config('bot_guard.form_signal.honeypot_field', 'website');
    }

    public function messages(): array
    {
        // Deliberately vague for the bot-guard fields. A precise message ("the
        // honeypot was missing") is a free tuning signal for whoever is scripting
        // this, and no genuine user ever sees these — the real form always sends
        // valid values. The wording still gives a human a sensible next step in
        // the one legitimate case: a form left open past max_form_age_seconds.
        $vague = 'We could not verify this submission. Please reload the page and try again.';

        return [
            'email.unique'                      => 'An account with this email already exists.',
            'email_confirmation.same'           => 'The email addresses do not match.',
            'agree_terms.accepted'              => 'You must accept the terms and conditions to register.',
            'agree_ecomm_consent.accepted'      => 'You must accept the e-commerce consent to register.',
            'agree_accuracy_confirmed.accepted' => 'You must confirm the accuracy of your information.',
            'primary_phone.regex'               => 'Please provide a valid phone number.',

            $this->honeypotField() . '.present' => $vague,
            $this->honeypotField() . '.max'     => $vague,
            'form_elapsed_ms.present'           => $vague,
            'form_elapsed_ms.required'          => $vague,
            'form_elapsed_ms.integer'           => $vague,
            'form_elapsed_ms.min'               => $vague,
            'form_elapsed_ms.max'               => $vague,
        ];
    }

    /**
     * Log blocked bot submissions so the campaign stays visible after it stops
     * showing up as rows in the users table.
     */
    protected function failedValidation(Validator $validator): void
    {
        $guardFields = [$this->honeypotField(), 'form_elapsed_ms'];
        $tripped     = array_intersect($guardFields, array_keys($validator->errors()->toArray()));

        if ($tripped !== []) {
            Log::warning('bot_guard: registration blocked by form signal', [
                'tripped'    => array_values($tripped),
                'ip'         => $this->ip(),
                'user_agent' => $this->userAgent(),
                'email'      => $this->input('email'),
            ]);
        }

        parent::failedValidation($validator);
    }
}
