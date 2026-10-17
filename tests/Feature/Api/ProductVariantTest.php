<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
});

it('supports multiple variants with independent prices and attributes', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'T-Shirts']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Basic T-Shirt', 'sku' => 'TSHIRT-BASE', 'selling_price' => 500]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'variant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $red = $this->actingAs($user)->postJson("/api/products/{$product->id}/variants", [
        'name' => 'Red / Large',
        'attributes' => ['color' => 'Red', 'size' => 'L'],
        'sku' => 'TSHIRT-RED-L',
        'selling_price' => 650,
        'min_selling_price' => 600,
    ])->assertCreated();
    $blue = $this->actingAs($user)->postJson("/api/products/{$product->id}/variants", [
        'name' => 'Blue / Medium',
        'attributes' => ['color' => 'Blue', 'size' => 'M'],
        'sku' => 'TSHIRT-BLUE-M',
        'selling_price' => 625,
    ])->assertCreated();

    $red->assertJsonPath('data.selling_price', '650.00')
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.attributes.color', 'Red');
    $blue->assertJsonPath('data.selling_price', '625.00');
    $this->actingAs($user)->getJson("/api/products/{$product->id}/variants")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('does not expose variants from another branch', function () {
    $main = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $other = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $category = Category::create(['branch_id' => $other->id, 'name' => 'T-Shirts']);
    $product = Product::create(['branch_id' => $other->id, 'category_id' => $category->id, 'name' => 'Other Shirt', 'sku' => 'OTHER-BASE', 'selling_price' => 500]);
    $user = User::factory()->create(['branch_id' => $main->id]);
    $role = Role::create(['name' => 'variant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $this->actingAs($user)->getJson("/api/products/{$product->id}/variants")->assertNotFound();
});

it('updates and deactivates a variant without exposing it in the active variant list', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'T-Shirts']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Basic T-Shirt', 'sku' => 'TSHIRT-BASE', 'selling_price' => 500]);
    $variant = ProductVariant::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'name' => 'Red / Large',
        'sku' => 'TSHIRT-RED-L',
        'selling_price' => 650,
    ]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'variant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $this->actingAs($user)->patchJson("/api/products/{$product->id}/variants/{$variant->id}", [
        'selling_price' => 700,
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.selling_price', '700.00')
        ->assertJsonPath('data.is_active', false);
    $this->actingAs($user)->getJson("/api/products/{$product->id}/variants")->assertOk()->assertJsonCount(0, 'data');

    expect($variant->fresh()->selling_price)->toBe('700.00')
        ->and($variant->fresh()->is_active)->toBeFalse();
});

it('creates a variable product with embedded variants in a single request', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Shoes']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'product-creator', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/products', [
        'category_id' => $category->id,
        'name' => 'Running Shoes',
        'sku' => 'RUN-SHOE-BASE',
        'product_type' => 'variable',
        'selling_price' => 2500,
        'variants' => [
            [
                'name' => 'Running Shoes - Black / 42',
                'sku' => 'RUN-SHOE-BLK-42',
                'selling_price' => 2500,
                'attributes' => ['color' => 'Black', 'size' => '42'],
            ],
            [
                'name' => 'Running Shoes - White / 43',
                'sku' => 'RUN-SHOE-WHT-43',
                'selling_price' => 2600,
                'attributes' => ['color' => 'White', 'size' => '43'],
            ],
        ],
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'Running Shoes')
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.sku', 'RUN-SHOE-BLK-42')
        ->assertJsonPath('data.variants.1.sku', 'RUN-SHOE-WHT-43');

    $productId = $response->json('data.id');
    expect(ProductVariant::where('product_id', $productId)->count())->toBe(2);
});
