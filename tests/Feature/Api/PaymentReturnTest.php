<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
});

function posUser(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::firstOrCreate(['name' => 'pos-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('manage pos');
    $user->assignRole($role);

    return $user;
}

it('collects a due payment and processes a sale return', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 10]);
    $user = posUser($branch);
    $sale = $this->actingAs($user)->postJson('/api/sales', ['invoice_number' => 'INV-001', 'items' => [['product_id' => $product->id, 'quantity' => 2]]])->json('data');

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 20, 'method' => 'cash'])->assertCreated();
    expect(Sale::find($sale['id'])->due_amount)->toBe('20.00')
        ->and(Sale::find($sale['id'])->payment_status)->toBe('partial');

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 20, 'method' => 'cash'])->assertCreated();
    expect(Sale::find($sale['id'])->due_amount)->toBe('0.00')
        ->and(Sale::find($sale['id'])->payment_status)->toBe('paid');

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", ['return_number' => 'RET-001', 'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]]])->assertCreated();
    expect(BranchStock::where(['branch_id' => $branch->id, 'product_id' => $product->id])->value('quantity'))->toBe(9)
        ->and(StockMovement::where('type', 'sale_return')->count())->toBe(1);
});

it('rejects returning more than sold quantity', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 2]);
    $user = posUser($branch);
    $sale = $this->actingAs($user)->postJson('/api/sales', ['invoice_number' => 'INV-001', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('data');

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", ['return_number' => 'RET-001', 'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]]])->assertUnprocessable();
});

it('records cash refunds against an open register session', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 2]);
    $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Front Counter', 'code' => 'FRONT']);
    $user = posUser($branch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 100,
        'opened_at' => now(),
        'status' => 'open',
    ]);
    $sale = $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-REFUND-001',
        'paid_amount' => 40,
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertCreated()->json('data');

    $response = $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", [
        'return_number' => 'RET-CASH-001',
        'refund_amount' => 15,
        'refund_method' => 'cash',
        'cash_register_session_id' => $session->id,
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.refund_amount', '15.00')
        ->assertJsonPath('data.refund_status', 'refunded')
        ->assertJsonPath('data.refund_method', 'cash');
    expect($response->json('data.refunded_at'))->not->toBeNull();
    $this->assertDatabaseHas('cash_movements', [
        'cash_register_session_id' => $session->id,
        'direction' => 'out',
        'category' => 'sale_refund',
        'amount' => 15,
        'reference_type' => SaleReturn::class,
        'reference_id' => $response->json('data.id'),
    ]);

    $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/close", [
        'closing_balance' => 85,
    ])->assertOk()
        ->assertJsonPath('data.expected_balance', '85.00')
        ->assertJsonPath('data.difference', '0.00');
});

it('rejects sale refunds above the returned total or the collected amount', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'Water', 'sku' => 'WATER-001', 'selling_price' => 20]);
    BranchStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 2]);
    $user = posUser($branch);
    $sale = $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-REFUND-002',
        'paid_amount' => 10,
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertCreated()->json('data');
    $payload = [
        'return_number' => 'RET-INVALID-001',
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
    ];

    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", [
        ...$payload,
        'refund_amount' => 21,
        'refund_method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Refund cannot exceed the returned item total.');
    $this->actingAs($user)->postJson("/api/sales/{$sale['id']}/returns", [
        ...$payload,
        'refund_amount' => 11,
        'refund_method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Refund exceeds the amount collected for this sale.');

    expect(SaleReturn::count())->toBe(0);
});
