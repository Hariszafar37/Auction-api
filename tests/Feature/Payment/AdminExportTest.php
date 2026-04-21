<?php

use App\Enums\InvoiceStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Admin invoice list ───────────────────────────────────────────────────────

test('admin can list all invoices', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->makeInvoice($buyer);
    $this->makeInvoice($buyer);
    $admin = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->getJson('/api/v1/admin/invoices')
         ->assertOk()
         ->assertJsonPath('meta.total', 2);
});

test('non admin cannot access admin invoice list', function () {
    $buyer = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
         ->getJson('/api/v1/admin/invoices')
         ->assertForbidden();
});

test('admin invoice list filters by status', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->makeInvoice($buyer, ['status' => InvoiceStatus::Pending]);
    $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);
    $admin = $this->makeAdmin();

    $response = $this->actingAs($admin, 'sanctum')
         ->getJson('/api/v1/admin/invoices?status=paid')
         ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.status'))->toBe('paid');
});

test('admin invoice list filters by buyer id', function () {
    $buyer1 = User::factory()->create(['status' => 'active']);
    $buyer2 = User::factory()->create(['status' => 'active']);
    $this->makeInvoice($buyer1);
    $this->makeInvoice($buyer1);
    $this->makeInvoice($buyer2);
    $admin = $this->makeAdmin();

    $response = $this->actingAs($admin, 'sanctum')
         ->getJson("/api/v1/admin/invoices?buyer_id={$buyer1->id}")
         ->assertOk();

    expect($response->json('meta.total'))->toBe(2);
});

test('admin invoice detail includes buyer name and email', function () {
    $buyer   = User::factory()->create(['name' => 'Alice Smith', 'status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->getJson("/api/v1/admin/invoices/{$invoice->id}")
         ->assertOk()
         ->assertJsonPath('data.buyer.name', 'Alice Smith')
         ->assertJsonPath('data.buyer.email', $buyer->email);
});

// ─── CSV export ───────────────────────────────────────────────────────────────

test('admin csv export returns correct content type header', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->makeInvoice($buyer);
    $admin = $this->makeAdmin();

    $response = $this->actingAs($admin, 'sanctum')
         ->get('/api/v1/admin/invoices/export');

    $response->assertOk()
             ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

test('admin csv export includes correct column headers', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->makeInvoice($buyer);
    $admin = $this->makeAdmin();

    $content = $this->actingAs($admin, 'sanctum')
         ->get('/api/v1/admin/invoices/export')
         ->streamedContent();

    expect($content)->toContain('Invoice #')
        ->and($content)->toContain('Buyer Name')
        ->and($content)->toContain('Total')
        ->and($content)->toContain('Balance Due')
        ->and($content)->toContain('Status');
});

test('admin csv export respects status filter', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $paidInvoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);
    $this->makeInvoice($buyer, ['status' => InvoiceStatus::Pending]);
    $admin = $this->makeAdmin();

    $content = $this->actingAs($admin, 'sanctum')
         ->get('/api/v1/admin/invoices/export?status=paid')
         ->streamedContent();

    expect($content)->toContain($paidInvoice->invoice_number)
        ->and($content)->not->toContain('pending');
});

test('non admin cannot access csv export', function () {
    $buyer = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
         ->get('/api/v1/admin/invoices/export')
         ->assertForbidden();
});
