<?php

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    // The console is restricted to specific operators on top of role:admin — see
    // SpamPurgeAccessTest for that gate. These tests exercise the console's own
    // behaviour, so the acting admin is placed on the allowlist.
    config()->set('bot_guard.purge_allowlist', $this->admin->email);
});

function spamUser(int $id, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'first_name' => 'hKhPmploaIgTZdDuvcXul',
        'last_name'  => 'rbXOYBmDXngiwKbATIckP',
        'status'     => 'pending_email_verification',
    ], $attributes));

    $user->forceFill(['id' => $id])->save();

    return User::find($id);
}

// ── Access control ────────────────────────────────────────────────────────────

it('is unreachable without authentication', function () {
    $this->getJson('/api/v1/admin/spam-registrations')->assertStatus(401);
    $this->postJson('/api/v1/admin/spam-registrations/purge', [
        'user_ids' => [99], 'confirm' => 'DELETE',
    ])->assertStatus(401);
});

it('is unreachable by a buyer', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(403);
});

it('is unreachable by staff, who hold users.view but not users.purge', function () {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->assignRole('staff');

    $this->actingAs($staff, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertStatus(403);
});

// ── Review ────────────────────────────────────────────────────────────────────

it('lists candidates above the boundary with a verdict for each', function () {
    $spam = spamUser(101, ['email' => 'spam@example.com']);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertOk();

    $row = collect($response->json('data.candidates'))->firstWhere('id', $spam->id);

    expect($row)->not->toBeNull()
        ->and($row['deletable'])->toBeTrue()
        ->and($row['blockers'])->toBe([])
        ->and($row['looks_automated'])->toBeTrue()
        ->and($row['name_score'])->toBeGreaterThanOrEqual(5);
});

it('reports why a blocked account cannot be removed', function () {
    $active  = spamUser(150, ['email' => 'real@example.com']);
    $vehicle = Vehicle::factory()->create();

    DB::table('vehicle_notification_subscriptions')->insert([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $active->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertOk();

    $row = collect($response->json('data.candidates'))->firstWhere('id', $active->id);

    expect($row['deletable'])->toBeFalse()
        ->and($row['blockers'])->toContain('vehicle_notification_subscriptions');
});

it('never lists accounts at or below the boundary', function () {
    $genuine = spamUser(40, ['email' => 'genuine@example.com']);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/admin/spam-registrations')
        ->assertOk();

    expect(collect($response->json('data.candidates'))->pluck('id'))
        ->not->toContain($genuine->id);
});

// ── Purge ─────────────────────────────────────────────────────────────────────

it('deletes the requested accounts', function () {
    $a = spamUser(201, ['email' => 'a@example.com']);
    $b = spamUser(202, ['email' => 'b@example.com']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$a->id, $b->id],
            'confirm'  => 'DELETE',
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted', [$a->id, $b->id]);

    $this->assertDatabaseMissing('users', ['id' => $a->id]);
    $this->assertDatabaseMissing('users', ['id' => $b->id]);
});

it('requires the typed confirmation', function () {
    $spam = spamUser(210, ['email' => 'spam@example.com']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', ['user_ids' => [$spam->id]])
        ->assertStatus(422);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$spam->id],
            'confirm'  => 'yes',
        ])
        ->assertStatus(422);

    $this->assertDatabaseHas('users', ['id' => $spam->id]);
});

it('refuses an id below the boundary even when explicitly asked', function () {
    // The whole point of re-deriving server-side: a tampered request must not be
    // able to reach a genuine account.
    $genuine = spamUser(12, ['email' => 'genuine@example.com']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$genuine->id],
            'confirm'  => 'DELETE',
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted', [])
        ->assertJsonPath('data.skipped.0.id', $genuine->id);

    $this->assertDatabaseHas('users', ['id' => $genuine->id]);
});

it('refuses an account with real activity even when explicitly asked', function () {
    $active  = spamUser(220, ['email' => 'real@example.com']);
    $vehicle = Vehicle::factory()->create();

    DB::table('vehicle_notification_subscriptions')->insert([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $active->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$active->id],
            'confirm'  => 'DELETE',
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted', []);

    $this->assertDatabaseHas('users', ['id' => $active->id]);
    $this->assertDatabaseHas('vehicle_notification_subscriptions', ['user_id' => $active->id]);
});

it('refuses a privileged account even when explicitly asked', function () {
    $otherAdmin = spamUser(230, ['email' => 'admin2@example.com']);
    $otherAdmin->assignRole('admin');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$otherAdmin->id],
            'confirm'  => 'DELETE',
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted', []);

    $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
});

it('cannot be widened by lowering the boundary in the request', function () {
    $genuine = spamUser(5, ['email' => 'genuine@example.com']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge?after_id=0', [
            'user_ids' => [$genuine->id],
            'confirm'  => 'DELETE',
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted', []);

    $this->assertDatabaseHas('users', ['id' => $genuine->id]);
});

it('accounts for every id it was given', function () {
    $ok      = spamUser(240, ['email' => 'ok@example.com']);
    $genuine = spamUser(9, ['email' => 'genuine@example.com']);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/spam-registrations/purge', [
            'user_ids' => [$ok->id, $genuine->id, 999999],
            'confirm'  => 'DELETE',
        ])
        ->assertOk();

    $deleted = $response->json('data.deleted');
    $skipped = collect($response->json('data.skipped'))->pluck('id')->all();

    expect($deleted)->toBe([$ok->id])
        ->and($skipped)->toEqualCanonicalizing([$genuine->id, 999999]);
});
