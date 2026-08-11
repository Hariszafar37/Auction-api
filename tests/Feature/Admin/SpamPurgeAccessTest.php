<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    config()->set('bot_guard.purge_allowlist', 'zeeshan.sardar+10@provelopers.net');
});

function adminWithEmail(string $email): User
{
    $user = User::factory()->create(['email' => $email, 'status' => 'active']);
    $user->assignRole('admin');

    return $user;
}

// ── The allowlisted operator ──────────────────────────────────────────────────

it('lets the allowlisted operator in', function () {
    $operator = adminWithEmail('zeeshan.sardar+10@provelopers.net');

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertOk();
});

it('lets the allowlisted operator purge', function () {
    $operator = adminWithEmail('zeeshan.sardar+10@provelopers.net');

    $spam = User::factory()->create(['email' => 'spam@example.com']);
    $spam->forceFill(['id' => 300])->save();

    $this->actingAs($operator, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [300],
            'confirm'  => 'DELETE',
        ])
        ->assertOk();

    $this->assertDatabaseMissing('users', ['id' => 300]);
});

it('matches the allowlist case-insensitively and ignores stray whitespace', function () {
    config()->set('bot_guard.purge_allowlist', '  ZEESHAN.Sardar+10@ProVelopers.net , someone@else.com ');

    $this->actingAs(adminWithEmail('zeeshan.sardar+10@provelopers.net'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertOk();
});

// ── Everyone else ─────────────────────────────────────────────────────────────

it('hides the console from an ordinary admin', function () {
    // The whole point of this change: holding the admin role is no longer enough.
    $this->actingAs(adminWithEmail('another.admin@example.com'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(404);
});

it('stops an ordinary admin purging, even with a valid payload', function () {
    $spam = User::factory()->create(['email' => 'spam@example.com']);
    $spam->forceFill(['id' => 301])->save();

    $this->actingAs(adminWithEmail('another.admin@example.com'), 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [301],
            'confirm'  => 'DELETE',
        ])
        ->assertStatus(404);

    $this->assertDatabaseHas('users', ['id' => 301]);
});

it('answers 404 rather than 403 so the console is not advertised', function () {
    $this->actingAs(adminWithEmail('another.admin@example.com'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('is unreachable by a buyer', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(403);
});

it('is unreachable unauthenticated', function () {
    $this->getJson('/api/v1/admin/spam-registrations')->assertStatus(401);
});

// ── Fail closed ───────────────────────────────────────────────────────────────

it('denies everyone when the allowlist is empty', function () {
    // A blank or mistyped env value must lock the door, never open it.
    config()->set('bot_guard.purge_allowlist', '');

    $this->actingAs(adminWithEmail('zeeshan.sardar+10@provelopers.net'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(404);
});

it('denies everyone when the allowlist is only separators', function () {
    config()->set('bot_guard.purge_allowlist', ' , , ');

    $this->actingAs(adminWithEmail('zeeshan.sardar+10@provelopers.net'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(404);
});

it('does not treat the untagged address as equivalent to the tagged one', function () {
    // "+10" identifies a specific mailbox here; stripping it would widen access.
    $this->actingAs(adminWithEmail('zeeshan.sardar@provelopers.net'), 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(404);
});

// ── The flag the frontend navigates by ────────────────────────────────────────

it('reports can_purge_spam true only for the allowlisted operator', function () {
    $operator = adminWithEmail('zeeshan.sardar+10@provelopers.net');
    $other    = adminWithEmail('another.admin@example.com');

    $this->actingAs($operator, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.can_purge_spam', true);

    $this->actingAs($other, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.can_purge_spam', false);
});
