<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage products', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
});

it('makes transferred variant stock available for destination-branch sales', function () {
    $sourceBranch = Branch::create(['name' => 'Source Branch', 'code' => 'SRC']);
    $destinationBranch = Branch::create(['name' => 'Destination Branch', 'code' => 'DST']);
    $category = Category::create(['branch_id' => $sourceBranch->id, 'name' => 'Apparel']);
    $product = Product::create([
        'branch_id' => $sourceBranch->id,
        'category_id' => $category->id,
        'name' => 'T-Shirt',
        'sku' => 'SHIRT',
        'selling_price' => 500,
    ]);
    $variant = ProductVariant::create([
        'branch_id' => $sourceBranch->id,
        'product_id' => $product->id,
        'name' => 'Red / Large',
        'sku' => 'SHIRT-RED-L',
        'selling_price' => 650,
    ]);
    BranchStock::create(['branch_id' => $sourceBranch->id, 'product_id' => $product->id, 'quantity' => 10]);
    BranchStock::create(['branch_id' => $sourceBranch->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 8]);
    $user = User::factory()->create(['branch_id' => $sourceBranch->id]);
    $role = Role::create(['name' => 'stock-transporter', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/stock-transfers', [
        'from_branch_id' => $sourceBranch->id,
        'to_branch_id' => $destinationBranch->id,
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.items.0.variant.id', $variant->id);
    $this->actingAs($user)->getJson('/api/branches')->assertOk()->assertJsonCount(2, 'data');
    expect(BranchStock::where(['branch_id' => $sourceBranch->id, 'product_id' => $product->id])->whereNull('variant_id')->value('quantity'))->toBe(10)
        ->and(BranchStock::where(['branch_id' => $sourceBranch->id, 'product_id' => $product->id, 'variant_id' => $variant->id])->value('quantity'))->toBe(5)
        ->and(BranchStock::where(['branch_id' => $destinationBranch->id, 'product_id' => $product->id, 'variant_id' => $variant->id])->value('quantity'))->toBe(3)
        ->and(StockTransferItem::where('stock_transfer_id', $response->json('data.id'))->value('variant_id'))->toBe($variant->id)
        ->and(StockMovement::where(['type' => 'transfer_out', 'variant_id' => $variant->id])->value('balance_after'))->toBe(5)
        ->and(StockMovement::where(['type' => 'transfer_in', 'variant_id' => $variant->id])->value('balance_after'))->toBe(3);

    $adminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage pos');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);
    $this->actingAs($admin)->getJson("/api/pos/catalog?branch_id={$destinationBranch->id}")
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $product->id)
        ->assertJsonPath('data.data.0.variants.0.stock.quantity', 3);
    $this->actingAs($admin)->postJson('/api/sales', [
        'branch_id' => $destinationBranch->id,
        'invoice_number' => 'INV-TRANSFERRED',
        'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]],
    ])->assertCreated();
    expect(BranchStock::where(['branch_id' => $destinationBranch->id, 'product_id' => $product->id, 'variant_id' => $variant->id])->value('quantity'))->toBe(2);
});

it('returns 422 when a transfer has duplicate product lines', function () {
    $sourceBranch = Branch::create(['name' => 'Source Branch', 'code' => 'SRC']);
    $destinationBranch = Branch::create(['name' => 'Destination Branch', 'code' => 'DST']);
    $category = Category::create(['branch_id' => $sourceBranch->id, 'name' => 'Apparel']);
    $product = Product::create([
        'branch_id' => $sourceBranch->id,
        'category_id' => $category->id,
        'name' => 'T-Shirt',
        'sku' => 'SHIRT',
        'selling_price' => 500,
    ]);
    BranchStock::create(['branch_id' => $sourceBranch->id, 'product_id' => $product->id, 'quantity' => 10]);
    $user = User::factory()->create(['branch_id' => $sourceBranch->id]);
    $role = Role::create(['name' => 'stock-transporter', 'guard_name' => 'web']);
    $role->givePermissionTo('manage products');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/stock-transfers', [
        'from_branch_id' => $sourceBranch->id,
        'to_branch_id' => $destinationBranch->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 3],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('items.1.product_id');
    expect($response->json('errors')['items.1.product_id'][0])->toBe('The items.1.product_id field has a duplicate value.');

    expect(BranchStock::where(['branch_id' => $sourceBranch->id, 'product_id' => $product->id])->value('quantity'))->toBe(10)
        ->and(StockTransfer::count())->toBe(0);
});
