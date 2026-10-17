<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage expenses', 'guard_name' => 'web']);
    Permission::create(['name' => 'view reports', 'guard_name' => 'web']);
});

it('creates branch expenses and returns dashboard totals', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'accountant', 'guard_name' => 'web']);
    $role->givePermissionTo(['manage expenses', 'view reports']);
    $user->assignRole($role);

    $this->actingAs($user)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 5000, 'spent_at' => '2026-09-06'])->assertCreated();
    $this->actingAs($user)->getJson('/api/reports/dashboard')
        ->assertOk()
        ->assertJsonPath('data.expense_total', '5000.00')
        ->assertJsonPath('data.sales_count', 0);
    expect(Expense::count())->toBe(1);
});

it('stores and updates expense payment and receipt details', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'accountant', 'guard_name' => 'web']);
    $role->givePermissionTo('manage expenses');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/expenses', [
        'category' => 'Utilities',
        'amount' => 1200,
        'payment_method' => 'bank_transfer',
        'reference' => 'BANK-001',
        'spent_at' => '2026-10-13',
        'receipt_path' => 'receipts/utilities-001.pdf',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.payment_method', 'bank_transfer')
        ->assertJsonPath('data.reference', 'BANK-001')
        ->assertJsonPath('data.receipt_path', 'receipts/utilities-001.pdf');
    $expenseId = $response->json('data.id');
    $this->assertDatabaseHas('expenses', [
        'id' => $expenseId,
        'payment_method' => 'bank_transfer',
        'reference' => 'BANK-001',
        'receipt_path' => 'receipts/utilities-001.pdf',
    ]);

    $this->actingAs($user)->putJson("/api/expenses/{$expenseId}", [
        'payment_method' => 'cash',
        'reference' => 'CASH-002',
        'receipt_path' => null,
    ])->assertOk()
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.reference', 'CASH-002')
        ->assertJsonPath('data.receipt_path', null);
});
