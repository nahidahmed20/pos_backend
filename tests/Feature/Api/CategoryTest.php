<?php

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
});

it('stores and updates category slugs descriptions and image paths', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'category-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/categories', [
        'name' => 'Beverages',
        'slug' => 'beverages',
        'description' => 'Drinks and bottled water',
        'image_path' => 'categories/beverages.png',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.slug', 'beverages')
        ->assertJsonPath('data.description', 'Drinks and bottled water')
        ->assertJsonPath('data.image_path', 'categories/beverages.png');
    $categoryId = $response->json('data.id');
    $this->assertDatabaseHas('categories', [
        'id' => $categoryId,
        'slug' => 'beverages',
        'description' => 'Drinks and bottled water',
        'image_path' => 'categories/beverages.png',
    ]);

    $this->actingAs($user)->putJson("/api/categories/{$categoryId}", [
        'slug' => 'cold-drinks',
        'description' => 'Cold drinks and water',
        'image_path' => null,
    ])->assertOk()
        ->assertJsonPath('data.slug', 'cold-drinks')
        ->assertJsonPath('data.description', 'Cold drinks and water')
        ->assertJsonPath('data.image_path', null);
});
