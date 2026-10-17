<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage branches', 'guard_name' => 'web']);
});

it('logs in through the API and returns the user payload', function () {
    $user = User::factory()->create([
        'email' => 'next@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'next@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', $user->email);

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

it('returns the authenticated user for the Next.js app', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage branches');
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role);

    $response = $this->actingAs($user)->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.branch.code', 'MAIN')
        ->assertJsonPath('user.permissions.0', 'manage branches');
});

it('returns all active branches to a super admin and only the assigned branch to an admin', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage branches');
    $superAdminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $superAdminRole->givePermissionTo('manage branches');

    $mainBranch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $secondBranch = Branch::create(['name' => 'Second Branch', 'code' => 'SECOND']);
    $admin = User::factory()->create(['branch_id' => $mainBranch->id]);
    $admin->assignRole($role);
    $superAdmin = User::factory()->create(['branch_id' => null]);
    $superAdmin->assignRole($superAdminRole);

    $this->actingAs($admin)->getJson('/api/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'MAIN');

    $this->actingAs($superAdmin)->getJson('/api/branches')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['code' => $secondBranch->code]);
});

it('rejects unauthenticated API access', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});
