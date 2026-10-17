<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage branches', 'guard_name' => 'web']);
});

it('creates branches with their configured contact and invoice fields', function () {
    $user = User::factory()->create(['branch_id' => null]);
    $role = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage branches');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/branches', [
        'name' => 'Uttara Branch',
        'code' => 'UTTARA',
        'phone' => '021234567',
        'email' => 'uttara@example.com',
        'tax_number' => 'TIN-UTTARA',
        'currency' => 'BDT',
        'timezone' => 'Asia/Dhaka',
        'invoice_prefix' => 'UTR',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.phone', '021234567')
        ->assertJsonPath('data.email', 'uttara@example.com')
        ->assertJsonPath('data.tax_number', 'TIN-UTTARA')
        ->assertJsonPath('data.invoice_prefix', 'UTR')
        ->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('branches', [
        'id' => $response->json('data.id'),
        'phone' => '021234567',
        'email' => 'uttara@example.com',
        'tax_number' => 'TIN-UTTARA',
        'invoice_prefix' => 'UTR',
    ]);
});

it('allows branch administrators to update only their own branch', function () {
    $mainBranch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $user = User::factory()->create(['branch_id' => $mainBranch->id]);
    $role = Role::create(['name' => 'branch-admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage branches');
    $user->assignRole($role);

    $this->actingAs($user)->putJson("/api/branches/{$mainBranch->id}", [
        'phone' => '021234567',
        'tax_number' => 'TIN-MAIN',
    ])->assertOk()
        ->assertJsonPath('data.phone', '021234567')
        ->assertJsonPath('data.tax_number', 'TIN-MAIN');

    $this->actingAs($user)->getJson("/api/branches/{$otherBranch->id}")
        ->assertNotFound();
    $this->actingAs($user)->postJson('/api/branches', [
        'name' => 'Unauthorized Branch',
        'code' => 'UNAUTH',
    ])->assertForbidden();
});
