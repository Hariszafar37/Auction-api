<?php

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionTerm;
use App\Models\AuctionTermAcceptance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Master document ─────────────────────────────────────────────────────────

it('lets an admin view the current master terms', function () {
    $this->actingAsAdmin();

    $this->getJson('/api/v1/admin/auction-terms')
        ->assertOk()
        ->assertJsonPath('data.version', '1.0')
        ->assertJsonPath('data.checkbox_label', 'I have read, understand, and agree to the Auction Terms & Conditions.');
});

it('bumps the version when an admin updates the terms', function () {
    $this->actingAsAdmin();

    $this->putJson('/api/v1/admin/auction-terms', [
        'intro'          => 'New intro copy.',
        'checkbox_label' => 'I agree to the updated terms.',
    ])
        ->assertOk()
        ->assertJsonPath('data.version', '1.1')
        ->assertJsonPath('data.intro', 'New intro copy.');

    // History is retained — the old version still exists, only one is current.
    expect(AuctionTerm::count())->toBe(2)
        ->and(AuctionTerm::where('is_current', true)->count())->toBe(1)
        ->and(AuctionTerm::current()->version)->toBe('1.1');
});

it('rejects publishing when the submitted content is unchanged', function () {
    $this->actingAsAdmin();
    $current = AuctionTerm::current();

    $payload = [
        'header'                => $current->header,
        'intro'                 => $current->intro,
        'important_information' => $current->important_information,
        'full_terms_content'    => $current->full_terms_content,
        'checkbox_label'        => $current->checkbox_label,
        'fees_url'              => $current->fees_url,
        'payment_policy_url'    => $current->payment_policy_url,
    ];

    $this->putJson('/api/v1/admin/auction-terms', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'no_changes');

    // No duplicate version was created — still only the seeded v1.0.
    expect(AuctionTerm::count())->toBe(1)
        ->and(AuctionTerm::current()->version)->toBe('1.0');
});

it('publishes a new version when a single field changes', function () {
    $this->actingAsAdmin();
    $current = AuctionTerm::current();

    $payload = [
        'header'                => 'Enter the Auction Now',
        'intro'                 => $current->intro,
        'important_information' => $current->important_information,
        'full_terms_content'    => $current->full_terms_content,
        'checkbox_label'        => $current->checkbox_label,
        'fees_url'              => $current->fees_url,
        'payment_policy_url'    => $current->payment_policy_url,
    ];

    $this->putJson('/api/v1/admin/auction-terms', $payload)
        ->assertOk()
        ->assertJsonPath('data.version', '1.1')
        ->assertJsonPath('data.header', 'Enter the Auction Now');
});

it('validates url fields on update', function () {
    $this->actingAsAdmin();

    $this->putJson('/api/v1/admin/auction-terms', ['fees_url' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fees_url']);
});

it('forbids non-admins from viewing or updating the terms', function () {
    $this->actingAsBuyer();

    $this->getJson('/api/v1/admin/auction-terms')->assertForbidden();
    $this->putJson('/api/v1/admin/auction-terms', ['intro' => 'x'])->assertForbidden();
});

// ── Acceptance log + export ─────────────────────────────────────────────────

function seedAcceptance(string $name, string $email, string $auctionTitle): AuctionTermAcceptance
{
    $user = User::factory()->create(['name' => $name, 'email' => $email, 'status' => 'active']);
    $auction = Auction::create([
        'title'      => $auctionTitle,
        'location'   => 'New York, NY',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $user->id,
    ]);
    $terms = AuctionTerm::current();

    return AuctionTermAcceptance::create([
        'auction_id'       => $auction->id,
        'auction_terms_id' => $terms->id,
        'user_id'          => $user->id,
        'terms_version'    => $terms->version,
        'accepted_at'      => now(),
        'ip_address'       => '203.0.113.7',
    ]);
}

it('lists acceptances with all reporting fields', function () {
    seedAcceptance('Jane Bidder', 'jane@example.com', 'Spring Sale');
    $this->actingAsAdmin();

    $this->getJson('/api/v1/admin/auction-terms/acceptances')
        ->assertOk()
        ->assertJsonPath('data.0.user_name', 'Jane Bidder')
        ->assertJsonPath('data.0.email', 'jane@example.com')
        ->assertJsonPath('data.0.auction_name', 'Spring Sale')
        ->assertJsonPath('data.0.terms_version', '1.0')
        ->assertJsonPath('data.0.ip_address', '203.0.113.7');
});

it('filters the acceptance log by search term', function () {
    seedAcceptance('Jane Bidder', 'jane@example.com', 'Spring Sale');
    seedAcceptance('Bob Dealer', 'bob@example.com', 'Fall Sale');
    $this->actingAsAdmin();

    $res = $this->getJson('/api/v1/admin/auction-terms/acceptances?search=bob')->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.email'))->toBe('bob@example.com');
});

it('exports the acceptance log as CSV', function () {
    seedAcceptance('Jane Bidder', 'jane@example.com', 'Spring Sale');
    $this->actingAsAdmin();

    $res = $this->get('/api/v1/admin/auction-terms/acceptances/export');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');

    $body = $res->streamedContent();
    expect($body)->toContain('User Name')
        ->and($body)->toContain('Jane Bidder')
        ->and($body)->toContain('jane@example.com')
        ->and($body)->toContain('Spring Sale');
});

it('forbids non-admins from the acceptance log and export', function () {
    $this->actingAsBuyer();

    $this->getJson('/api/v1/admin/auction-terms/acceptances')->assertForbidden();
    $this->get('/api/v1/admin/auction-terms/acceptances/export')->assertForbidden();
});
