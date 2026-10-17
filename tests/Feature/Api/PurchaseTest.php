<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage purchases', 'guard_name' => 'web']);
});

it('receives a purchase and increases branch stock with a ledger entry', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Acme Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-001',
        'purchased_at' => '2026-09-06',
        'paid_amount' => 50,
        'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 5]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.subtotal', '50.00')
        ->assertJsonPath('data.total', '50.00')
        ->assertJsonPath('data.due_amount', '0.00')
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.status', 'received');
    expect($response->json('data.received_at'))->not->toBeNull();
    expect(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id])->value('quantity'))->toBe(10)
        ->and(StockMovement::where('type', 'purchase')->count())->toBe(1)
        ->and(Payment::where('purchase_id', $response->json('data.id'))->value('amount'))->toBe('50.00');
});

it('collects purchase due payments and updates payment status', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Acme Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);
    $purchaseResponse = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-001',
        'purchased_at' => '2026-09-06',
        'paid_amount' => 20,
        'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 5]],
    ]);
    $purchaseResponse->assertCreated()
        ->assertJsonPath('data.due_amount', '30.00')
        ->assertJsonPath('data.payment_status', 'partial');
    $purchaseId = $purchaseResponse->json('data.id');

    $this->actingAs($user)->postJson("/api/purchases/{$purchaseId}/payments", [
        'amount' => 30,
        'method' => 'bank_transfer',
        'reference' => 'BANK-001',
    ])->assertCreated()
        ->assertJsonPath('data.amount', '30.00')
        ->assertJsonPath('data.purchase.due_amount', '0.00')
        ->assertJsonPath('data.purchase.payment_status', 'paid');

    expect(Purchase::findOrFail($purchaseId)->paid_amount)->toBe('50.00')
        ->and(Payment::where('purchase_id', $purchaseId)->count())->toBe(2);
});

it('rejects purchase payments that exceed the remaining due', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Acme Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);
    $purchaseResponse = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-001',
        'purchased_at' => '2026-09-06',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 5]],
    ]);
    $purchaseResponse->assertCreated();
    $purchaseId = $purchaseResponse->json('data.id');

    $this->actingAs($user)->postJson("/api/purchases/{$purchaseId}/payments", [
        'amount' => 6,
        'method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Payment exceeds due amount.');

    expect(Purchase::findOrFail($purchaseId)->paid_amount)->toBe('0.00')
        ->and(Payment::where('purchase_id', $purchaseId)->exists())->toBeFalse();
});

it('does not allow purchase payments across branches', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $supplier = Supplier::create(['branch_id' => $otherBranch->id, 'name' => 'Other Supplier']);
    $purchase = Purchase::create([
        'branch_id' => $otherBranch->id,
        'supplier_id' => $supplier->id,
        'created_by' => User::factory()->create(['branch_id' => $otherBranch->id])->id,
        'invoice_number' => 'PUR-OTHER-001',
        'purchased_at' => '2026-09-06',
        'subtotal' => 50,
        'total' => 50,
        'paid_amount' => 0,
        'due_amount' => 50,
        'payment_status' => 'unpaid',
    ]);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $this->actingAs($user)->postJson("/api/purchases/{$purchase->id}/payments", [
        'amount' => 10,
        'method' => 'cash',
    ])->assertNotFound();

    expect(Purchase::findOrFail($purchase->id)->paid_amount)->toBe('0.00')
        ->and(Payment::where('purchase_id', $purchase->id)->exists())->toBeFalse();
});

it('prevents invalid purchase payment and cross-branch supplier use', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    $supplier = Supplier::create(['branch_id' => $otherBranch->id, 'name' => 'Other Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $payload = ['supplier_id' => $supplier->id, 'invoice_number' => 'PUR-001', 'purchased_at' => '2026-09-06', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 5]]];
    $this->actingAs($user)->postJson('/api/purchases', [...$payload, 'paid_amount' => 99])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/purchases', $payload)->assertUnprocessable();
});

it('receives a color and size variant into its own stock row', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'T-Shirts']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'T-Shirt', 'sku' => 'TSHIRT-BASE', 'selling_price' => 500]);
    $variant = ProductVariant::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'name' => 'Red / Large', 'attributes' => ['color' => 'Red', 'size' => 'L'], 'sku' => 'TSHIRT-RED-L', 'selling_price' => 650]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Clothing Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'variant-purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-VAR-001',
        'purchased_at' => '2026-09-06',
        'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 8, 'unit_cost' => 400]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.items.0.variant.id', $variant->id);
    expect(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id, 'variant_id' => $variant->id])->value('quantity'))->toBe(8)
        ->and(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id, 'variant_id' => null])->exists())->toBeFalse()
        ->and(StockMovement::where(['product_id' => $product->id, 'variant_id' => $variant->id])->count())->toBe(1);
});

it('stores purchase batch and expiry details for each received line', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Medicine']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Vitamin C', 'sku' => 'VIT-C', 'selling_price' => 20]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Health Supplier']);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::create(['name' => 'batch-purchaser', 'guard_name' => 'web']);
    $role->givePermissionTo('manage purchases');
    $user->assignRole($role);

    $response = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-BATCH-001',
        'purchased_at' => '2026-09-06',
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => 8,
            'batch_number' => 'LOT-2026-09',
            'manufactured_date' => '2026-08-01',
            'expiry_date' => '2027-08-01',
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.items.0.batch_number', 'LOT-2026-09')
        ->assertJsonPath('data.items.0.manufactured_date', '2026-08-01')
        ->assertJsonPath('data.items.0.expiry_date', '2027-08-01');
});
