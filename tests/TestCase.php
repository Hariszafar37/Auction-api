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
}
