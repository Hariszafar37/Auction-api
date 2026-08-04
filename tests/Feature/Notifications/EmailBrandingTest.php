<?php

use App\Models\User;
use App\Support\Brand;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/*
|------------------------------------------------------------------------------
| Email branding
|------------------------------------------------------------------------------
|
| Production sent transactional mail as "CarAuction" — MAIL_FROM_NAME was set
| to "${APP_NAME}", and Laravel's default markdown mail templates printed
| config('app.name') in the header, footer and the "Regards," salutation.
|
| These tests deliberately set app.name to the WRONG value. If any of them
| fail, a customer-visible surface has been re-coupled to the per-server app
| name and the bug has regressed.
|
*/

beforeEach(function () {
    // Simulate a server whose APP_NAME is misconfigured, exactly as production was.
    config([
        'app.name'       => 'CarAuction',
        'mail.from.name' => Brand::NAME,
    ]);
});

// ─── Sender identity ─────────────────────────────────────────────────────────

it('sends all mail from the brand identity, never the per-server env', function () {
    // Reload the real config file rather than the value stubbed above, to prove
    // the file itself does not read MAIL_FROM_NAME / MAIL_FROM_ADDRESS / APP_NAME.
    $mail = require config_path('mail.php');

    expect($mail['from']['name'])->toBe('Colonial Auction Services')
        ->and($mail['from']['name'])->not->toContain('CarAuction')
        ->and($mail['from']['address'])->toBe('noreply@colonialauctions.com')
        ->and($mail['from']['address'])->not->toContain('example.com');
});

it('does not let MAIL_FROM_* env values override the brand sender', function () {
    putenv('MAIL_FROM_ADDRESS=leaked@wrong-domain.test');
    putenv('MAIL_FROM_NAME=CarAuction');

    try {
        $mail = require config_path('mail.php');

        expect($mail['from']['address'])->toBe('noreply@colonialauctions.com')
            ->and($mail['from']['name'])->toBe('Colonial Auction Services');
    } finally {
        putenv('MAIL_FROM_ADDRESS');
        putenv('MAIL_FROM_NAME');
    }
});

it('exposes a brand config that is independent of app.name', function () {
    expect(config('brand.name'))->toBe('Colonial Auction Services')
        ->and(config('brand.legal_name'))->toBe('Colonial Auction Services, Inc.')
        ->and(config('app.name'))->toBe('CarAuction'); // sanity: the stub is live
});

// ─── Shared markdown layout ──────────────────────────────────────────────────
// Every MailMessage-based notification renders through these templates, so
// asserting once here covers all of them.

it('renders the brand name and logo in markdown mail, never the app name', function () {
    $html = (new MailMessage)
        ->subject('Branding probe')
        ->greeting('Hello Test,')
        ->line('Body copy.')
        ->action('Do the thing', 'https://example.test/go')
        ->render();

    expect((string) $html)
        ->toContain('Colonial Auction Services')          // header / footer / salutation
        ->toContain(Brand::LOGO_PATH)                     // logo actually embedded
        ->toContain('Regards,')
        ->not->toContain('CarAuction');
});

it('places the brand name directly under the Regards salutation', function () {
    $html = (string) (new MailMessage)->line('Body copy.')->render();

    // Collapse whitespace so the assertion is not tied to markdown line wrapping.
    $flat = preg_replace('/\s+/', ' ', strip_tags($html));

    expect($flat)->toContain('Regards, Colonial Auction Services');
});

it('renders a wide logo, not the framework 75x75 square', function () {
    $html = (string) (new MailMessage)->line('Body copy.')->render();

    // The framework theme hard-codes width:75px on .logo, which squashed the
    // wordmark. The published theme must not reintroduce it.
    expect($html)->toContain('170px')
        ->and($html)->not->toMatch('/\.logo[^}]*width:\s*75px/');

    // A fixed width plus any height cap distorts rather than scales, so the
    // inlined logo style must leave the height free.
    preg_match('/<img[^>]*class="logo"[^>]*>/', $html, $img);

    expect($img)->not->toBeEmpty('No logo <img> in the mail header')
        ->and($img[0])->toContain('height: auto')
        ->and($img[0])->not->toContain('max-height');
});

// ─── Framework notifications ─────────────────────────────────────────────────
// These are the two the user actually receives first: verify-email and reset.

it('brands the email verification notification', function () {
    $user = User::factory()->create(['status' => 'pending_email_verification']);

    $html = (string) (new VerifyEmail)->toMail($user)->render();

    expect($html)
        ->toContain('Colonial Auction Services')
        ->toContain(Brand::LOGO_PATH)
        ->not->toContain('CarAuction');
});

it('brands the password reset notification', function () {
    $user = User::factory()->create(['status' => 'active']);

    $html = (string) (new ResetPassword('test-token'))->toMail($user)->render();

    expect($html)
        ->toContain('Colonial Auction Services')
        ->toContain(Brand::LOGO_PATH)
        ->not->toContain('CarAuction');
});

// ─── Drift guards ────────────────────────────────────────────────────────────

it('has no customer-facing mail template still bound to config(app.name)', function () {
    $dirs = [
        resource_path('views/emails'),
        resource_path('views/vendor/mail'),
        resource_path('views/vendor/notifications'),
    ];

    $offenders = [];

    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Strip Blade comments first — the published overrides document
            // what they changed and legitimately name the old binding in prose.
            $body = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));

            if (str_contains($body, "config('app.name')")) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBeEmpty(
        "These mail templates still print the per-server app name:\n" . implode("\n", $offenders)
    );
});

it('spells the legal name without a doubled period in rendered mail', function () {
    // Source scanning alone is not enough here: the footer composes the name
    // from config and a literal ".", which only doubles up once rendered.
    $html = (string) (new MailMessage)->line('Body copy.')->render();

    expect(strip_tags($html))->not->toContain('Colonial Auction Services, Inc..')
        ->and(strip_tags($html))->toContain('Colonial Auction Services, Inc. All rights reserved.');
});

it('spells the legal name without a doubled period in source', function () {
    $dirs = [app_path('Notifications'), app_path('Mail'), resource_path('views')];
    $offenders = [];

    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'Colonial Auction Services, Inc..')) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBeEmpty('Doubled period after "Inc." in: ' . implode(', ', $offenders));
});
