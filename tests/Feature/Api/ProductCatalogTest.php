<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
});

it('creates branch catalog data and updates its stock', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $categoryResponse = $this->actingAs($user)->postJson('/api/categories', [
        'name' => 'Beverages',
    ]);
    $categoryResponse->assertCreated();

    $productResponse = $this->actingAs($user)->postJson('/api/products', [
        'category_id' => $categoryResponse->json('data.id'),
        'name' => 'Mineral Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    $productResponse->assertCreated()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.category.name', 'Beverages');

    $productId = $productResponse->json('data.id');
    $this->actingAs($user)->putJson("/api/products/{$productId}/stock", [
        'quantity' => 25,
        'reorder_level' => 5,
    ])->assertOk()
        ->assertJsonPath('data.quantity', 25);

    $this->actingAs($user)->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stock.quantity', 25);
});

it('does not expose another branch catalog to a branch admin', function () {
    $mainBranch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $mainCategory = Category::create(['branch_id' => $mainBranch->id, 'name' => 'Main']);
    $otherCategory = Category::create(['branch_id' => $otherBranch->id, 'name' => 'Other']);
    $otherProduct = Product::create([
        'branch_id' => $otherBranch->id,
        'category_id' => $otherCategory->id,
        'name' => 'Other Product',
        'sku' => 'OTHER-001',
        'selling_price' => 30,
    ]);
    $user = User::factory()->create(['branch_id' => $mainBranch->id]);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($user)->getJson("/api/products/{$otherProduct->id}")
        ->assertNotFound();

    $this->actingAs($user)->postJson('/api/products', [
        'category_id' => $otherCategory->id,
        'name' => 'Invalid Product',
        'sku' => 'INVALID-001',
        'selling_price' => 10,
    ])->assertUnprocessable();

    expect($mainCategory->exists)->toBeTrue();
});

it('rejects invalid stock and users without product permission', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Mineral Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    $cashier = User::factory()->create(['branch_id' => $branch->id]);
    $cashierRole = Role::create(['name' => 'cashier', 'guard_name' => 'web']);
    $cashier->assignRole($cashierRole);

    $this->actingAs($cashier)->getJson('/api/products')->assertForbidden();

    $manager = User::factory()->create(['branch_id' => $branch->id]);
    $managerRole = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $managerRole->givePermissionTo('manage products');
    $manager->assignRole($managerRole);

    $this->actingAs($manager)->putJson("/api/products/{$product->id}/stock", [
        'quantity' => -1,
    ])->assertUnprocessable();
});

it('supports child categories and prevents cross-branch or cyclic parents', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $parent = $this->actingAs($user)->postJson('/api/categories', ['name' => 'Electronics'])->assertCreated();
    $parentId = $parent->json('data.id');

    $child = $this->actingAs($user)->postJson('/api/categories', [
        'name' => 'Mobile',
        'parent_id' => $parentId,
    ])->assertCreated();
    $childId = $child->json('data.id');

    $this->actingAs($user)->getJson('/api/categories')
        ->assertOk()
        ->assertJsonPath('data.1.parent.id', $parentId);

    $otherParent = Category::create(['branch_id' => $otherBranch->id, 'name' => 'Other']);
    $this->actingAs($user)->putJson("/api/categories/{$parentId}", [
        'parent_id' => $childId,
    ])->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/categories', [
        'name' => 'Invalid Child',
        'parent_id' => $otherParent->id,
    ])->assertUnprocessable();
});
