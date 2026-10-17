<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage purchases', 'guard_name' => 'web']);
});

it('stores and updates the supplier contact and opening balance fields', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'supplier-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/suppliers', [
        'name' => 'Acme Supplier',
        'phone' => '01800000000',
        'contact_person' => 'Aminul Islam',
        'tax_number' => 'TIN-001',
        'opening_balance' => 125.50,
        'credit_limit' => 500,
        'notes' => 'Preferred supplier',
        'is_active' => false,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.contact_person', 'Aminul Islam')
        ->assertJsonPath('data.tax_number', 'TIN-001')
        ->assertJsonPath('data.opening_balance', '125.50')
        ->assertJsonPath('data.notes', 'Preferred supplier')
        ->assertJsonPath('data.is_active', false);
    $supplierId = $response->json('data.id');
    $this->assertDatabaseHas('suppliers', [
        'id' => $supplierId,
        'contact_person' => 'Aminul Islam',
        'tax_number' => 'TIN-001',
        'opening_balance' => 125.50,
        'credit_limit' => 500,
        'notes' => 'Preferred supplier',
        'is_active' => false,
    ]);

    $this->actingAs($user)->putJson("/api/suppliers/{$supplierId}", [
        'contact_person' => 'Nusrat Jahan',
        'tax_number' => 'TIN-002',
        'opening_balance' => 200,
        'credit_limit' => 750,
        'notes' => 'Updated supplier',
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('data.contact_person', 'Nusrat Jahan')
        ->assertJsonPath('data.tax_number', 'TIN-002')
        ->assertJsonPath('data.opening_balance', '200.00')
        ->assertJsonPath('data.credit_limit', '750.00')
        ->assertJsonPath('data.notes', 'Updated supplier')
        ->assertJsonPath('data.is_active', true);
});

it('creates suppliers with their migrated fields when those fields are omitted', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'supplier-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/suppliers', [
        'name' => 'Acme Supplier',
        'phone' => '01800000000',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.credit_limit', '0.00')
        ->assertJsonPath('data.opening_balance', '0.00');
    $this->assertDatabaseHas('suppliers', [
        'id' => $response->json('data.id'),
        'is_active' => true,
        'credit_limit' => 0,
        'opening_balance' => 0,
    ]);

    $this->assertDatabaseCount('suppliers', 1);
});
