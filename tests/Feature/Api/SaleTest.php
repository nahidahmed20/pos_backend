<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
});

it('completes a sale, deducts stock, records payment and due amount', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 10]);
    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Rahim', 'phone' => '01700000000']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'cashier-pos', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/sales', [
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-001',
        'paid_amount' => 30,
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.total', '60.00')
        ->assertJsonPath('data.paid_amount', '30.00')
        ->assertJsonPath('data.due_amount', '30.00');
    expect(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id])->value('quantity'))->toBe(7)
        ->and(Sale::count())->toBe(1)
        ->and(StockMovement::where('type', 'sale')->count())->toBe(1);
    $this->assertDatabaseHas('stock_movements', [
        'type' => 'sale',
        'reference_type' => Sale::class,
        'reference_id' => $response->json('data.id'),
    ]);
});

it('rejects a sale when stock is insufficient', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 1]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'cashier-pos', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-001',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertUnprocessable();
    expect(Sale::count())->toBe(0);
});

it('provides POS users with only active sellable products from their branch', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $activeProduct = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Mineral Water',
        'sku' => 'WATER-001',
        'barcode' => '123456',
        'selling_price' => 20,
    ]);
    Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Inactive Water',
        'sku' => 'WATER-002',
        'selling_price' => 15,
        'is_active' => false,
    ]);
    Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Not Sellable Water',
        'sku' => 'WATER-003',
        'selling_price' => 12,
        'is_sellable' => false,
    ]);
    $otherCategory = Category::create(['branch_id' => $otherBranch->id, 'name' => 'Beverages']);
    Product::create([
        'branch_id' => $otherBranch->id,
        'category_id' => $otherCategory->id,
        'name' => 'Other Branch Water',
        'sku' => 'OTHER-WATER',
        'selling_price' => 22,
    ]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'catalog-cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/pos/catalog?search=Water')
        ->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $activeProduct->id)
        ->assertJsonPath('data.data.0.stock', null);

    $this->actingAs($user)->getJson('/api/pos/catalog?search=123456')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $activeProduct->id);
});

it('provides purchase managers with active purchasable products only', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Supplies']);
    $buyable = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Packaging',
        'sku' => 'PACK-001',
        'selling_price' => 4,
        'is_sellable' => false,
        'is_purchasable' => true,
    ]);
    Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Discontinued',
        'sku' => 'PACK-002',
        'selling_price' => 4,
        'is_active' => false,
        'is_purchasable' => true,
    ]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchase-catalog-user', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('manage purchases', 'web'));
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/pos/catalog?purpose=purchase')
        ->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $buyable->id);

    $this->actingAs($user)->getJson('/api/pos/catalog')->assertForbidden();
});

it('sells multiple variants independently and returns stock to the sold variant', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'T-Shirts']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Basic T-Shirt',
        'sku' => 'TSHIRT-BASE',
        'selling_price' => 500,
    ]);
    $large = ProductVariant::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'name' => 'Red / Large',
        'sku' => 'TSHIRT-RED-L',
        'selling_price' => 650,
    ]);
    $medium = ProductVariant::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'name' => 'Blue / Medium',
        'sku' => 'TSHIRT-BLUE-M',
        'selling_price' => 600,
    ]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'variant_id' => $large->id, 'quantity' => 3]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'variant_id' => $medium->id, 'quantity' => 2]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'variant-pos-cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    $sale = $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-VARIANT-001',
        'items' => [
            ['product_id' => $product->id, 'variant_id' => $large->id, 'quantity' => 1],
            ['product_id' => $product->id, 'variant_id' => $medium->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.total', '1250.00')
        ->json('data');

    expect(BranchStock::where(['product_id' => $product->id, 'variant_id' => $large->id])->value('quantity'))->toBe(2)
        ->and(BranchStock::where(['product_id' => $product->id, 'variant_id' => $medium->id])->value('quantity'))->toBe(1)
        ->and(count($sale['items']))->toBe(2);

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", [
        'return_number' => 'RET-VARIANT-001',
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
    ])->assertCreated();

    $this->actingAs($user)->getJson('/api/sales')
        ->assertOk()
        ->assertJsonPath('data.data.0.returns.0.items.0.sale_item_id', $sale['items'][0]['id']);

    expect(BranchStock::where(['product_id' => $product->id, 'variant_id' => $large->id])->value('quantity'))->toBe(3)
        ->and(BranchStock::where(['product_id' => $product->id, 'variant_id' => $medium->id])->value('quantity'))->toBe(1);
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'variant_id' => $large->id,
        'type' => 'sale_return',
        'balance_after' => 3,
    ]);
});

it('filters sales and paginates the matching results', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 10]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'sale-history-cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);
    $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'paid_amount' => 0, 'payment_method' => 'due'];

    $this->actingAs($user)->postJson('/api/sales', [...$payload, 'invoice_number' => 'INV-MATCH'])->assertCreated();
    $this->actingAs($user)->postJson('/api/sales', [...$payload, 'invoice_number' => 'INV-OTHER'])->assertCreated();

    $this->actingAs($user)->getJson('/api/sales?invoice_number=INV-MATCH&payment_status=unpaid&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.data.0.invoice_number', 'INV-MATCH');
});
