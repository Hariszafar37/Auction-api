<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin edit of a target user's core profile fields (name / email / phones).
 *
 * Mirrors User\UpdateProfileRequest but ignores the *route* user for the email
 * uniqueness check (not the authenticated admin), so admins can edit any user
 * without colliding with their own email.
 */
class AdminUpdateUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name'            => ['sometimes', 'string', 'max:255'],
            'email'           => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'primary_phone'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'secondary_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Admin-only override of the auto-assigned bidder number. Must stay
            // unique across users (ignoring the row being edited).
            'bidder_number'   => ['sometimes', 'integer', 'min:1', Rule::unique('users', 'bidder_number')->ignore($userId)],
        ];
    }
}
