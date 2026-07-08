<?php

use App\Models\AccountAction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function makeReportAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

/** Perform a real admin action so an audit row is written through the normal path. */
function suspendViaApi(User $admin, User $target, string $reason = 'test'): void
{
    test()->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended', 'reason' => $reason])
        ->assertStatus(200);
}

it('lists account actions across all users, newest first, with affected user detail', function () {
    $admin = makeReportAdmin();
    $alice = User::factory()->create(['status' => 'active', 'name' => 'Alice A', 'email' => 'alice@example.com']);
    $bob   = User::factory()->create(['status' => 'active', 'name' => 'Bob B', 'email' => 'bob@example.com']);

    suspendViaApi($admin, $alice, 'alice reason');
    suspendViaApi($admin, $bob, 'bob reason');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/account-actions')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');

    // Newest first — Bob was suspended last.
    $response->assertJsonPath('data.0.action', 'suspended')
             ->assertJsonPath('data.0.user.name', 'Bob B')
             ->assertJsonPath('data.0.user.email', 'bob@example.com')
             ->assertJsonPath('data.0.reason', 'bob reason')
             ->assertJsonPath('data.0.performed_by', $admin->name);

    $response->assertJsonPath('data.1.user.name', 'Alice A');

    // Pagination meta present.
    $response->assertJsonPath('meta.total', 2);
});

it('filters by user search (name or email)', function () {
    $admin = makeReportAdmin();
    $alice = User::factory()->create(['status' => 'active', 'name' => 'Alice A', 'email' => 'alice@example.com']);
    $bob   = User::factory()->create(['status' => 'active', 'name' => 'Bob B', 'email' => 'bob@example.com']);

    suspendViaApi($admin, $alice);
    suspendViaApi($admin, $bob);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/account-actions?search=alice')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.name', 'Alice A');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/account-actions?search=bob@example.com')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.name', 'Bob B');
});

it('filters by action type', function () {
    $admin = makeReportAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    suspendViaApi($admin, $user);
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/bidding", ['enabled' => false])
        ->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/account-actions?action=bidding_disabled')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'bidding_disabled');
});

it('filters by performing admin', function () {
    $adminA = makeReportAdmin();
    $adminB = makeReportAdmin();
    $u1     = User::factory()->create(['status' => 'active']);
    $u2     = User::factory()->create(['status' => 'active']);

    suspendViaApi($adminA, $u1);
    suspendViaApi($adminB, $u2);

    $this->actingAs($adminA, 'sanctum')
        ->getJson("/api/v1/admin/account-actions?performed_by={$adminB->id}")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.performed_by', $adminB->name);
});

it('filters by date range', function () {
    $admin = makeReportAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    // Old action, backdated directly on the audit row.
    $old = AccountAction::create([
        'subject_user_id' => $user->id,
        'action'          => 'suspended',
        'previous_value'  => 'active',
        'new_value'       => 'suspended',
        'performed_by'    => $admin->id,
        'performed_at'    => now()->subDays(10),
    ]);
    // Recent action.
    AccountAction::create([
        'subject_user_id' => $user->id,
        'action'          => 'reactivated',
        'previous_value'  => 'suspended',
        'new_value'       => 'active',
        'performed_by'    => $admin->id,
        'performed_at'    => now(),
    ]);

    $from = now()->subDays(2)->toDateString();
    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/account-actions?date_from={$from}")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'reactivated');
});

it('forbids a non-admin from the account-action report', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/account-actions')
        ->assertStatus(403);
});

it('requires authentication for the account-action report', function () {
    $this->getJson('/api/v1/admin/account-actions')
        ->assertStatus(401);
});

// ══ CSV export ════════════════════════════════════════════════════════════════

it('exports the filtered dataset as CSV with friendly labels, newest first', function () {
    $admin = makeReportAdmin();
    $alice = User::factory()->create(['status' => 'active', 'name' => 'Alice A', 'email' => 'alice@example.com']);
    $bob   = User::factory()->create(['status' => 'active', 'name' => 'Bob B', 'email' => 'bob@example.com']);

    suspendViaApi($admin, $alice, 'alice reason');
    suspendViaApi($admin, $bob, 'bob reason');

    $response = $this->actingAs($admin, 'sanctum')
        ->get('/api/v1/admin/account-actions/export');

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('content-disposition'))->toContain('account-restrictions-');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // Header + 2 rows.
    expect($lines)->toHaveCount(3);
    expect($lines[0])->toContain('User Name')
        ->and($lines[0])->toContain('IP Address')
        ->and($lines[0])->toContain('User Agent');

    // Friendly label + newest first (Bob last-suspended → first data row).
    expect($lines[1])->toContain('Account Suspended')
        ->and($lines[1])->toContain('Bob B')
        ->and($lines[1])->toContain('bob reason');
    expect($lines[2])->toContain('Alice A');
});

it('CSV export respects the action filter', function () {
    $admin = makeReportAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    suspendViaApi($admin, $user);
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/bidding", ['enabled' => false])
        ->assertStatus(200);

    $csv = $this->actingAs($admin, 'sanctum')
        ->get('/api/v1/admin/account-actions/export?action=bidding_disabled')
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(2); // header + 1
    expect($lines[1])->toContain('Bidding Disabled')
        ->and($lines[1])->not->toContain('Account Suspended');
});

it('forbids a non-admin from exporting', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->get('/api/v1/admin/account-actions/export')
        ->assertStatus(403);
});

it('captures the acting admin IP and user agent on each action', function () {
    $admin = makeReportAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->withHeaders(['User-Agent' => 'PestBrowser/9.9'])
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended'])
        ->assertStatus(200);

    $row = AccountAction::where('subject_user_id', $user->id)->latest('id')->first();
    expect($row->ip_address)->not->toBeNull();
    expect($row->user_agent)->toBe('PestBrowser/9.9');
});

it('exposes performed_by_id, ip and user agent on the report rows', function () {
    $admin = makeReportAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended'])
        ->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/account-actions')
        ->assertStatus(200)
        ->assertJsonPath('data.0.performed_by_id', $admin->id)
        ->assertJsonPath('data.0.action_label', 'Account Suspended')
        ->assertJsonPath('data.0.user_agent', 'PestBrowser/1.0');
});
