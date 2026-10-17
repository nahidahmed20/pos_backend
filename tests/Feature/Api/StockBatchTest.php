<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\SaleReturnItemBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage purchases', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage pos', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage products', 'guard_name' => 'web']);
});

function expiryWorkflowUser(Branch $branch, array $permissions): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::firstOrCreate(['name' => 'expiry-workflow-manager', 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    $user->assignRole($role);

    return $user;
}

it('requires expiry dates when receiving products marked for expiry tracking', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Medicine']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C',
        'selling_price' => 20,
        'has_expiry' => true,
    ]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Health Supplier']);
    $user = expiryWorkflowUser($branch, ['manage purchases']);
    $payload = [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-EXP-001',
        'purchased_at' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 8, 'batch_number' => 'LOT-A']],
    ];

    $this->actingAs($user)->postJson('/api/purchases', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'An expiry date is required for expiry-tracked products.');
    expect(Purchase::count())->toBe(0);

    $response = $this->actingAs($user)->postJson('/api/purchases', [
        ...$payload,
        'invoice_number' => 'PUR-EXP-002',
        'items' => [[...$payload['items'][0], 'expiry_date' => now()->addDays(30)->toDateString()]],
    ]);

    $response->assertCreated();
    expect(InventoryBatch::where('branch_id', $branch->id)->value('quantity'))->toBe(4)
        ->and(InventoryBatch::where('branch_id', $branch->id)->value('batch_number'))->toBe('LOT-A');
});

it('sells only unexpired batches in expiry order and returns stock to its original batch', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Medicine']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C',
        'selling_price' => 20,
        'has_expiry' => true,
    ]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 7]);
    $expired = InventoryBatch::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'batch_number' => 'EXPIRED',
        'expiry_date' => now()->subDay()->toDateString(),
        'quantity' => 2,
        'unit_cost' => 8,
    ]);
    $soon = InventoryBatch::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'batch_number' => 'SOON',
        'expiry_date' => now()->addDays(5)->toDateString(),
        'quantity' => 2,
        'unit_cost' => 8,
    ]);
    $later = InventoryBatch::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'batch_number' => 'LATER',
        'expiry_date' => now()->addDays(20)->toDateString(),
        'quantity' => 3,
        'unit_cost' => 9,
    ]);
    $user = expiryWorkflowUser($branch, ['manage pos']);

    $sale = $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-FEFO-001',
        'items' => [['product_id' => $product->id, 'quantity' => 4]],
    ])->assertCreated()->json('data');

    expect($expired->fresh()->quantity)->toBe(2)
        ->and($soon->fresh()->quantity)->toBe(0)
        ->and($later->fresh()->quantity)->toBe(1)
        ->and(SaleItemBatch::where('sale_item_id', $sale['items'][0]['id'])->orderBy('id')->pluck('quantity')->all())->toBe([2, 2]);

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", [
        'return_number' => 'RET-FEFO-001',
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
    ])->assertCreated();
    expect($soon->fresh()->quantity)->toBe(1)
        ->and(SaleReturnItemBatch::count())->toBe(1);

    $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-FEFO-002',
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient unexpired batch stock.');
    expect(Sale::where('invoice_number', 'INV-FEFO-002')->exists())->toBeFalse();
});

it('blocks legacy expiry stock until its opening batch is registered', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Medicine']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C',
        'selling_price' => 20,
        'has_expiry' => true,
    ]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 5]);
    $user = expiryWorkflowUser($branch, ['manage pos', 'manage products']);

    $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-LEGACY-001',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient unexpired batch stock.');
    $this->actingAs($user)->getJson('/api/pos/catalog')
        ->assertOk()
        ->assertJsonPath('data.data.0.sellable_quantity', 0);

    $this->actingAs($user)->postJson('/api/stock-batches/opening', [
        'product_id' => $product->id,
        'quantity' => 5,
        'batch_number' => 'OPENING-LOT',
        'expiry_date' => now()->addDays(30)->toDateString(),
        'note' => 'Opening stock lot registration',
    ])->assertCreated();
    $this->actingAs($user)->getJson('/api/pos/catalog')
        ->assertOk()
        ->assertJsonPath('data.data.0.sellable_quantity', 5);

    $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-LEGACY-002',
        'items' => [['product_id' => $product->id, 'quantity' => 5]],
    ])->assertCreated();
});

it('requires batch dates for expiry-tracked stock adjustments and removes stock from registered batches', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Medicine']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C',
        'selling_price' => 20,
        'has_expiry' => true,
    ]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 7]);
    $batch = InventoryBatch::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'batch_number' => 'CURRENT-LOT',
        'expiry_date' => now()->addDays(30)->toDateString(),
        'quantity' => 5,
        'unit_cost' => 8,
    ]);
    $user = expiryWorkflowUser($branch, ['manage products']);

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'An expiry date is required for expiry-tracked stock adjustments.');

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'quantity' => 2,
        'batch_number' => 'ADJUSTMENT-LOT',
        'expiry_date' => now()->addDays(60)->toDateString(),
        'note' => 'Received stock',
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'quantity' => -8,
        'note' => 'Damaged stock',
    ])->assertCreated();

    expect($batch->fresh()->quantity)->toBe(0)
        ->and(InventoryBatch::where('batch_number', 'ADJUSTMENT-LOT')->value('quantity'))->toBe(0)
        ->and(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id])->value('quantity'))->toBe(1)
        ->and(StockMovement::where('type', 'adjustment')->latest('id')->value('balance_after'))->toBe(1);
});

it('transfers expiry batches between branches without losing their lot dates', function () {
    $sourceBranch = Branch::create(['name' => 'Source Branch', 'code' => 'SRC']);
    $destinationBranch = Branch::create(['name' => 'Destination Branch', 'code' => 'DST']);
    $category = Category::create(['branch_id' => $sourceBranch->id, 'name' => 'Medicine']);
    $product = Product::create([
        'branch_id' => $sourceBranch->id,
        'category_id' => $category->id,
        'name' => 'Vitamin C',
        'sku' => 'VIT-C',
        'selling_price' => 20,
        'has_expiry' => true,
    ]);
    BranchStock::create(['branch_id' => $sourceBranch->id, 'product_id' => $product->id, 'quantity' => 5]);
    $batch = InventoryBatch::create([
        'branch_id' => $sourceBranch->id,
        'product_id' => $product->id,
        'batch_number' => 'TRANSFER-LOT',
        'manufactured_date' => now()->subDays(5)->toDateString(),
        'expiry_date' => now()->addDays(30)->toDateString(),
        'quantity' => 5,
        'unit_cost' => 8,
    ]);
    $user = expiryWorkflowUser($sourceBranch, ['manage products']);

    $this->actingAs($user)->postJson('/api/stock-transfers', [
        'from_branch_id' => $sourceBranch->id,
        'to_branch_id' => $destinationBranch->id,
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ])->assertCreated();

    $destinationBatch = InventoryBatch::where('branch_id', $destinationBranch->id)->firstOrFail();
    expect($batch->fresh()->quantity)->toBe(2)
        ->and($destinationBatch->quantity)->toBe(3)
        ->and($destinationBatch->batch_number)->toBe('TRANSFER-LOT')
        ->and($destinationBatch->manufactured_date->toDateString())->toBe(now()->subDays(5)->toDateString())
        ->and($destinationBatch->expiry_date->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(BranchStock::where(['branch_id' => $destinationBranch->id, 'product_id' => $product->id])->value('quantity'))->toBe(3);
});
