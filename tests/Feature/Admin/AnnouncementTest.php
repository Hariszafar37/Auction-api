<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function draftAnnouncement(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::createAnnouncement(array_merge([
        'name'     => 'Holiday schedule',
        'subject'  => 'Holiday auction schedule',
        'title'    => 'Holiday schedule',
        'message'  => 'Hi {{first_name}}, our holiday hours are posted.',
        'audience' => ['type' => 'all'],
    ], $overrides));
}

// ── CRUD ─────────────────────────────────────────────────────────────────────

it('creates a draft announcement', function () {
    $this->actingAsAdmin()
        ->postJson('/api/v1/admin/announcements', [
            'name'     => 'Spring sale',
            'title'    => 'Spring sale is live',
            'message'  => 'Hello {{first_name}}!',
            'audience' => ['type' => 'roles', 'roles' => ['buyer']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.audience_label', 'Roles: Buyer');

    expect(NotificationTemplate::announcements()->count())->toBe(1);
});

it('keeps announcements out of the system template list', function () {
    draftAnnouncement();

    // The system endpoint must not surface announcements, and vice versa.
    $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

    $this->actingAsAdmin()
        ->getJson('/api/v1/admin/notification-templates')
        ->assertOk()
        ->assertJsonMissing(['category' => 'announcement']);

    $this->actingAsAdmin()
        ->getJson('/api/v1/admin/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects an empty targeted audience', function () {
    $this->actingAsAdmin()
        ->postJson('/api/v1/admin/announcements', [
            'name'     => 'Bad audience',
            'message'  => 'Hi',
            'audience' => ['type' => 'roles', 'roles' => []],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audience.roles');
});

it('rejects an announcement with no channel enabled', function () {
    $this->actingAsAdmin()
        ->postJson('/api/v1/admin/announcements', [
            'name'           => 'Silent',
            'message'        => 'Hi',
            'audience'       => ['type' => 'all'],
            'email_enabled'  => false,
            'in_app_enabled' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email_enabled');
});

it('edits a draft', function () {
    $announcement = draftAnnouncement();

    $this->actingAsAdmin()
        ->patchJson("/api/v1/admin/announcements/{$announcement->id}", [
            'name'     => 'Holiday schedule',
            'message'  => 'Updated copy for {{first_name}}.',
            'audience' => ['type' => 'all'],
        ])
        ->assertOk()
        ->assertJsonPath('data.message', 'Updated copy for {{first_name}}.');
});

it('deletes a draft', function () {
    $announcement = draftAnnouncement();

    $this->actingAsAdmin()
        ->deleteJson("/api/v1/admin/announcements/{$announcement->id}")
        ->assertOk();

    expect(NotificationTemplate::find($announcement->id))->toBeNull();
});

// ── Sending ──────────────────────────────────────────────────────────────────

it('sends to all active users and marks the announcement sent', function () {
    Notification::fake();

    User::factory()->count(3)->create(['status' => 'active']);
    User::factory()->create(['status' => 'suspended']);

    $announcement = draftAnnouncement();

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent');

    // 3 seeded active users + the acting admin (also active) = 4; the suspended
    // user is excluded.
    Notification::assertSentTimes(AnnouncementNotification::class, 4);

    expect($announcement->fresh()->sent_at)->not->toBeNull();
});

it('targets only the chosen roles', function () {
    Notification::fake();

    $dealer = User::factory()->create(['status' => 'active']);
    $dealer->assignRole('dealer');

    User::factory()->count(2)->create(['status' => 'active']); // buyers, not targeted

    $announcement = draftAnnouncement(['audience' => ['type' => 'roles', 'roles' => ['dealer']]]);

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/send")
        ->assertOk();

    Notification::assertSentTo($dealer, AnnouncementNotification::class);
    Notification::assertSentTimes(AnnouncementNotification::class, 1);
});

it('renders the announcement copy when sent', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);
    $announcement = draftAnnouncement(['message' => 'Hi {{first_name}}, welcome.']);

    $payload = (new AnnouncementNotification($announcement))->toDatabase($user);

    expect($payload['type'])->toBe('announcement')
        ->and($payload['message'])->toBe('Hi Alex, welcome.')
        ->and($payload['meta']['announcement_id'])->toBe($announcement->id);
});

it('honours the channel switches when sending', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);
    $announcement = draftAnnouncement(['email_enabled' => false]);

    expect((new AnnouncementNotification($announcement))->via($user))
        ->not->toContain('mail')
        ->toContain('database');
});

// ── Immutability once sent ───────────────────────────────────────────────────

it('refuses to edit a sent announcement', function () {
    $announcement = draftAnnouncement(['sent_at' => now()]);

    $this->actingAsAdmin()
        ->patchJson("/api/v1/admin/announcements/{$announcement->id}", [
            'name'     => 'x',
            'message'  => 'y',
            'audience' => ['type' => 'all'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'announcement_already_sent');
});

it('refuses to delete a sent announcement', function () {
    $announcement = draftAnnouncement(['sent_at' => now()]);

    $this->actingAsAdmin()
        ->deleteJson("/api/v1/admin/announcements/{$announcement->id}")
        ->assertStatus(422);
});

// ── Guards ───────────────────────────────────────────────────────────────────

it('404s when the announcement route is given a system template id', function () {
    $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    $system = NotificationTemplate::system()->first();

    $this->actingAsAdmin()
        ->getJson("/api/v1/admin/announcements/{$system->id}")
        ->assertNotFound();
});

it('blocks non-admins', function () {
    $this->actingAsBuyer()
        ->getJson('/api/v1/admin/announcements')
        ->assertForbidden();
});

it('previews copy and audience size before sending', function () {
    User::factory()->count(2)->create(['status' => 'active']);
    $announcement = draftAnnouncement();

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/preview", [
            'message' => 'Hey {{first_name}}!',
        ])
        ->assertOk()
        ->assertJsonPath('data.in_app.message', 'Hey Alex!')
        // 2 created + acting admin = 3 active users.
        ->assertJsonPath('data.recipient_count', 3);
});
