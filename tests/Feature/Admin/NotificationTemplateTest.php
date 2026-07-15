<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserDocument;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\DocumentNeedsResubmissionNotification;
use App\Support\NotificationTemplateDefaults;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    // actingAsAdmin()/actingAsBuyer() need the Spatie roles to exist.
    $this->seed(RolePermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
});

// -- Seeding / defaults -------------------------------------------------------

it('seeds every default template', function () {
    expect(NotificationTemplate::count())
        ->toBe(count(NotificationTemplateDefaults::all()));
});

it('creates a missing template on first access rather than failing', function () {
    NotificationTemplate::query()->where('key', 'poa_approved')->delete();

    $template = NotificationTemplate::forKey('poa_approved');

    expect($template->exists)->toBeTrue()
        ->and($template->subject)->toBe('Your Power of Attorney Has Been Approved');
});

it('enables all channels on a template it creates on the fly', function () {
    // Regression: create() returns only the attributes passed to it, so relying on
    // the column defaults left enabled/email_enabled null on the returned model and
    // activeChannels() reported the type as switched off.
    NotificationTemplate::query()->where('key', 'poa_approved')->delete();

    $template = NotificationTemplate::forKey('poa_approved');

    expect($template->enabled)->toBeTrue()
        ->and($template->activeChannels())->toContain('mail', 'database', 'broadcast');
});

it('rejects an unknown template key', function () {
    NotificationTemplate::forKey('does_not_exist');
})->throws(InvalidArgumentException::class);

it('does not overwrite admin-edited copy when reseeded', function () {
    $template = NotificationTemplate::forKey('poa_approved');
    $template->update(['subject' => 'Custom subject', 'email_enabled' => false]);

    $this->seed(NotificationTemplateSeeder::class);

    $fresh = NotificationTemplate::query()->where('key', 'poa_approved')->first();

    expect($fresh->subject)->toBe('Custom subject')
        ->and($fresh->email_enabled)->toBeFalse();
});

// -- Rendering ----------------------------------------------------------------

it('renders placeholders into the notification copy', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $document = new UserDocument(['type' => 'government_id', 'admin_notes' => 'Photo was blurry.']);
    $document->id = 1;

    $payload = (new DocumentNeedsResubmissionNotification($document))->toDatabase($user);

    expect($payload['message'])->toBe('Your Government-Issued ID needs to be resubmitted.')
        ->and($payload['type'])->toBe('document_needs_resubmission');
});

it('drops a body line whose placeholders all resolve empty', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $withNotes = new UserDocument(['type' => 'government_id', 'admin_notes' => 'Photo was blurry.']);
    $withNotes->id = 1;

    $withoutNotes = new UserDocument(['type' => 'government_id', 'admin_notes' => null]);
    $withoutNotes->id = 2;

    $withLines    = (new DocumentNeedsResubmissionNotification($withNotes))->toMail($user)->introLines;
    $withoutLines = (new DocumentNeedsResubmissionNotification($withoutNotes))->toMail($user)->introLines;

    expect($withLines)->toContain('Admin notes: Photo was blurry.')
        // The dangling "Admin notes:" label must not survive an empty value.
        ->and(implode(' ', $withoutLines))->not->toContain('Admin notes');
});

it('falls back to generic copy when the rejection reason is empty', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $payload = (new AccountRejectedNotification(null, 'seller'))->toDatabase($user);

    expect($payload['message'])->toBe('Your application could not be approved at this time.');
});

it('selects the template variant matching the context', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $dealer = (new AccountRejectedNotification('nope', 'dealer'))->toMail($user);
    $seller = (new AccountRejectedNotification('nope', 'seller'))->toMail($user);

    expect($dealer->subject)->toBe('Your Dealer Application Was Not Approved')
        ->and($seller->subject)->toBe('Your Seller Application Was Not Approved');
});

it('renders admin-edited copy rather than the shipped default', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    NotificationTemplate::forKey('document_needs_resubmission')
        ->update(['message' => 'Please re-send your {{document_label}}, {{first_name}}.']);

    $document = new UserDocument(['type' => 'government_id']);
    $document->id = 1;

    $payload = (new DocumentNeedsResubmissionNotification($document))->toDatabase($user);

    expect($payload['message'])->toBe('Please re-send your Government-Issued ID, Alex.');
});

// -- Channel switches ---------------------------------------------------------

it('drops the mail channel when an admin disables email', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $document = new UserDocument(['type' => 'government_id']);
    $document->id = 1;

    expect((new DocumentNeedsResubmissionNotification($document))->via($user))->toContain('mail');

    NotificationTemplate::forKey('document_needs_resubmission')->update(['email_enabled' => false]);

    expect((new DocumentNeedsResubmissionNotification($document))->via($user))
        ->not->toContain('mail')
        ->toContain('database');
});

it('sends on no channel at all when an admin disables the type', function () {
    $user = new User(['first_name' => 'Alex', 'name' => 'Alex Smith']);

    $document = new UserDocument(['type' => 'government_id']);
    $document->id = 1;

    NotificationTemplate::forKey('document_needs_resubmission')->update(['enabled' => false]);

    expect((new DocumentNeedsResubmissionNotification($document))->via($user))->toBe([]);
});

it('never offers mail for a type whose code has no mail channel', function () {
    // auction_won is emailed by AuctionWonMail; enabling email here would double-send.
    $template = NotificationTemplate::forKey('auction_won');

    expect($template->supported_channels)->not->toContain('mail')
        ->and($template->activeChannels())->not->toContain('mail');
});

// -- Admin API ----------------------------------------------------------------

it('lists templates for an admin', function () {
    $this->actingAsAdmin()
        ->getJson('/api/v1/admin/notification-templates')
        ->assertOk()
        ->assertJsonCount(count(NotificationTemplateDefaults::all()), 'data');
});

it('blocks non-admins from the templates list', function () {
    $this->actingAsBuyer()
        ->getJson('/api/v1/admin/notification-templates')
        ->assertForbidden();
});

it('updates template copy and switches', function () {
    $template = NotificationTemplate::forKey('poa_approved');

    $this->actingAsAdmin()
        ->patchJson("/api/v1/admin/notification-templates/{$template->id}", [
            'subject'       => 'Your POA is good to go, {{first_name}}',
            'email_enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Your POA is good to go, {{first_name}}')
        ->assertJsonPath('data.email_enabled', false);
});

it('rejects a placeholder the template cannot fill', function () {
    $template = NotificationTemplate::forKey('poa_approved');

    $this->actingAsAdmin()
        ->patchJson("/api/v1/admin/notification-templates/{$template->id}", [
            'subject' => 'Hello {{vehicel_name}}',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('subject');
});

it('ignores an email toggle for a type that cannot email', function () {
    $template = NotificationTemplate::forKey('auction_won');

    $this->actingAsAdmin()
        ->patchJson("/api/v1/admin/notification-templates/{$template->id}", [
            'email_enabled' => true,
        ])
        ->assertOk();

    expect($template->fresh()->activeChannels())->not->toContain('mail');
});

it('previews unsaved copy against sample values', function () {
    $template = NotificationTemplate::forKey('outbid');

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/notification-templates/{$template->id}/preview", [
            'message' => 'Outbid on {{vehicle_name}} at {{amount}}!',
        ])
        ->assertOk()
        ->assertJsonPath('data.in_app.message', 'Outbid on 2019 Toyota Camry at $12,500!');

    // Previewing must not persist the draft.
    expect($template->fresh()->message)->not->toBe('Outbid on {{vehicle_name}} at {{amount}}!');
});

it('restores the shipped copy but leaves channel switches alone', function () {
    $template = NotificationTemplate::forKey('poa_approved');
    $template->update(['subject' => 'Mangled', 'email_enabled' => false]);

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/notification-templates/{$template->id}/reset")
        ->assertOk();

    $fresh = $template->fresh();

    expect($fresh->subject)->toBe('Your Power of Attorney Has Been Approved')
        ->and($fresh->email_enabled)->toBeFalse();
});
