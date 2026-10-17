<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertNoContent();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertNoContent();
});

test('browser sessions can access the authenticated API', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNoContent();

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

test('guests can view the sign in page and are redirected from the POS', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in to your account');
    $this->get('/pos')->assertRedirect('/login');
});

test('only users with POS permission can open the workspace', function () {
    $user = User::factory()->create();
    $permission = Permission::findOrCreate('manage pos', 'web');
    $role = Role::create(['name' => 'workspace-cashier', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $this->actingAs($user)->get('/pos')
        ->assertOk()
        ->assertSee('Point of sale')
        ->assertSee('New purchase')
        ->assertSee('Add product')
        ->assertSee('Categories')
        ->assertSee('Stock transfers')
        ->assertSee('sales-invoice-filter')
        ->assertSee('sales-pagination');

    $this->actingAs(User::factory()->create())->get('/pos')->assertForbidden();
});

test('users with an admin permission can open the workspace without POS access', function () {
    $user = User::factory()->create();
    $permission = Permission::findOrCreate('manage purchases', 'web');
    $role = Role::create(['name' => 'workspace-purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $this->actingAs($user)->get('/pos')
        ->assertOk()
        ->assertSee('Purchases');
});
