<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
});

it('creates a complete product master record with brand and unit', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Electronics']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'product-admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/products', [
        'category_id' => $category->id,
        'brand' => 'Acme',
        'name' => 'Acme Keyboard',
        'sku' => 'KEY-001',
        'unit' => 'box',
        'product_type' => 'standard',
        'model_number' => 'K100',
        'description' => 'USB keyboard',
        'purchase_price' => 500,
        'wholesale_price' => 550,
        'selling_price' => 650,
        'min_selling_price' => 600,
        'tax_rate' => 5,
        'is_sellable' => true,
        'is_purchasable' => true,
        'track_stock' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.brand', 'Acme')
        ->assertJsonPath('data.unit', 'box')
        ->assertJsonPath('data.product_type', 'standard')
        ->assertJsonPath('data.tax_rate', '5.00');
});
