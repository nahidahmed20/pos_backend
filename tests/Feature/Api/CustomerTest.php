<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
});

it('stores and updates the customer contact and opening balance fields', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'customer-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/customers', [
        'name' => 'Acme Customer',
        'phone' => '01700000000',
        'contact_person' => 'Aminul Islam',
        'tax_number' => 'TIN-001',
        'opening_balance' => 125.50,
        'credit_limit' => 500,
        'notes' => 'Wholesale account',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.contact_person', 'Aminul Islam')
        ->assertJsonPath('data.tax_number', 'TIN-001')
        ->assertJsonPath('data.opening_balance', '125.50')
        ->assertJsonPath('data.notes', 'Wholesale account');
    $customerId = $response->json('data.id');
    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'contact_person' => 'Aminul Islam',
        'tax_number' => 'TIN-001',
        'opening_balance' => 125.50,
        'notes' => 'Wholesale account',
    ]);

    $this->actingAs($user)->putJson("/api/customers/{$customerId}", [
        'contact_person' => 'Nusrat Jahan',
        'tax_number' => 'TIN-002',
        'opening_balance' => 200,
        'notes' => 'Updated account',
    ])->assertOk()
        ->assertJsonPath('data.contact_person', 'Nusrat Jahan')
        ->assertJsonPath('data.tax_number', 'TIN-002')
        ->assertJsonPath('data.opening_balance', '200.00')
        ->assertJsonPath('data.notes', 'Updated account');
});

it('soft deletes customers using the deleted-at column', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $customer = Customer::create([
        'branch_id' => $branch->id,
        'name' => 'Acme Customer',
        'phone' => '01700000000',
    ]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'customer-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}")
        ->assertOk();

    $this->assertSoftDeleted($customer);
});

it('returns database defaults for omitted customer balances', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'customer-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/customers', [
        'name' => 'Acme Customer',
        'phone' => '01700000000',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.opening_balance', '0.00')
        ->assertJsonPath('data.credit_limit', '0.00');
});
