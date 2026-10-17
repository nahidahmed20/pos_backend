<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage users', 'guard_name' => 'web']);
});

it('limits branch admin user management to the assigned branch', function () {
    $mainBranch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $admin = User::factory()->create(['branch_id' => $mainBranch->id]);
    $mainUser = User::factory()->create(['branch_id' => $mainBranch->id]);
    $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage users');
    $admin->assignRole($role);

    $this->actingAs($admin)->getJson('/api/users')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['email' => $otherUser->email])
        ->assertJsonFragment(['email' => $mainUser->email]);

    $this->actingAs($admin)->postJson('/api/users', [
        'name' => 'Other Branch User',
        'email' => 'other-created@example.com',
        'password' => 'password123',
        'role' => 'admin',
        'branch_id' => $otherBranch->id,
    ])->assertCreated()
        ->assertJsonPath('data.branch_id', $mainBranch->id);
});

it('does not allow a branch admin to create a super admin', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $admin = User::factory()->create(['branch_id' => $branch->id]);
    $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage users');
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $admin->assignRole($adminRole);

    $this->actingAs($admin)->postJson('/api/users', [
        'name' => 'Unsafe User',
        'email' => 'unsafe@example.com',
        'password' => 'password123',
        'role' => 'super-admin',
    ])->assertForbidden();
});
