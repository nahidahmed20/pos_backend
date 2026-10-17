<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
});

function inventoryUser(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::firstOrCreate(['name' => 'inventory-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    return $user;
}

it('transfers stock between branches and records both ledger movements', function () {
    $from = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $to = Branch::create(['name' => 'Uttara Branch', 'code' => 'UTTARA']);
    $category = Category::create(['branch_id' => $from->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $from->id,
        'category_id' => $category->id,
        'name' => 'Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    BranchStock::create(['branch_id' => $from->id, 'product_id' => $product->id, 'quantity' => 50]);
    $user = inventoryUser($from);

    $response = $this->actingAs($user)->postJson('/api/stock-transfers', [
        'from_branch_id' => $from->id,
        'to_branch_id' => $to->id,
        'items' => [['product_id' => $product->id, 'quantity' => 15]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.items.0.quantity', 15);
    expect(BranchStock::where(['branch_id' => $from->id, 'product_id' => $product->id])->value('quantity'))->toBe(35)
        ->and(BranchStock::where(['branch_id' => $to->id, 'product_id' => $product->id])->value('quantity'))->toBe(15)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(2);
});

it('rejects insufficient stock and transfers from another branch', function () {
    $from = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $to = Branch::create(['name' => 'Uttara Branch', 'code' => 'UTTARA']);
    $category = Category::create(['branch_id' => $from->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $from->id,
        'category_id' => $category->id,
        'name' => 'Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    BranchStock::create(['branch_id' => $from->id, 'product_id' => $product->id, 'quantity' => 5]);
    $otherUser = inventoryUser($to);

    $this->actingAs($otherUser)->postJson('/api/stock-transfers', [
        'from_branch_id' => $from->id,
        'to_branch_id' => $to->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertForbidden();

    $fromUser = inventoryUser($from);
    $this->actingAs($fromUser)->postJson('/api/stock-transfers', [
        'from_branch_id' => $from->id,
        'to_branch_id' => $to->id,
        'items' => [['product_id' => $product->id, 'quantity' => 6]],
    ])->assertUnprocessable();
});

it('initializes stock on a positive adjustment and records the resulting balance', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    $user = inventoryUser($branch);

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'quantity' => 12,
        'note' => 'Initial count',
    ])->assertCreated()
        ->assertJsonPath('data.quantity', 12)
        ->assertJsonPath('data.balance_after', 12);

    $this->assertDatabaseHas('branch_stocks', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'quantity' => 12,
    ]);

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'quantity' => -13,
        'note' => 'Invalid removal',
    ])->assertUnprocessable();

    expect(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id])->value('quantity'))->toBe(12);
});

it('adjusts a variant stock row without changing base product stock', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'T-Shirts']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Basic T-Shirt',
        'sku' => 'TSHIRT-BASE',
        'selling_price' => 500,
    ]);
    $variant = ProductVariant::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'name' => 'Red / Large',
        'sku' => 'TSHIRT-RED-L',
        'selling_price' => 650,
    ]);
    $user = inventoryUser($branch);

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 4,
        'note' => 'Count variant stock',
    ])->assertCreated()
        ->assertJsonPath('data.variant_id', $variant->id)
        ->assertJsonPath('data.balance_after', 4);

    $this->assertDatabaseHas('branch_stocks', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 4,
    ]);
    $this->assertDatabaseMissing('branch_stocks', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'variant_id' => null,
    ]);
});
