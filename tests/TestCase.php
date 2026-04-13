<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assert that the response is a 403 Forbidden.
     */
    protected function assertForbidden(TestResponse $response): void
    {
        $response->assertStatus(403);
    }

    /**
     * Assert that the response is a 422 Unprocessable Entity
     * and contains a validation error for the given field.
     */
    protected function assertValidationError(TestResponse $response, string $field): void
    {
        $response->assertStatus(422)
                 ->assertJsonValidationErrors([$field]);
    }

    /**
     * Authenticate as a standard active buyer (no special roles).
     * Returns $this for fluent chaining.
     */
    protected function actingAsBuyer(array $attributes = []): static
    {
        $user = \App\Models\User::factory()->create(
            array_merge(['status' => 'active'], $attributes)
        );

        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Authenticate as an active dealer user.
     */
    protected function actingAsDealer(array $attributes = []): static
    {
        $user = \App\Models\User::factory()->create(array_merge([
            'status'       => 'active',
            'account_type' => 'dealer',
        ], $attributes));

        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Authenticate as an admin user.
     *
     * Requires RolePermissionSeeder to have run before this call so the
     * 'admin' Spatie role exists.
     */
    protected function actingAsAdmin(array $attributes = []): static
    {
        $user = \App\Models\User::factory()->create(
            array_merge(['status' => 'active'], $attributes)
        );
        $user->assignRole('admin');

        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Seed a valid payment method onto the given user so they satisfy the
     * BiddingService::requireValidPaymentMethod() gate.
     *
     * Creates or upserts the user_billing_information row with PCI-safe
     * metadata and an expiry far in the future. Any test that calls /bids
     * or /proxy-bid against a user who is expected to succeed must call
     * this helper first.
     */
    protected function givePaymentMethod(\App\Models\User $user): \App\Models\User
    {
        $user->billingInformation()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'billing_address'         => '123 Test St',
                'billing_country'         => 'US',
                'billing_city'            => 'Baltimore',
                'billing_zip_postal_code' => '21201',
                'payment_method_added'    => true,
                'cardholder_name'         => 'Test User',
                'card_brand'              => 'visa',
                'card_last_four'          => '4242',
                'card_expiry_month'       => 12,
                'card_expiry_year'        => (int) now()->year + 5,
            ]
        );

        // Reset the relation cache so callers using the same $user instance
        // immediately observe the new billing row via ->hasValidPaymentMethod().
        $user->unsetRelation('billingInformation');

        return $user;
    }
}
