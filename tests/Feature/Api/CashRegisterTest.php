<?php

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'manage branches', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage purchases', 'guard_name' => 'web']);
});

function cashRegisterUser(Branch $branch, string $permission = 'manage pos'): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::firstOrCreate(['name' => 'cash-register-'.$permission, 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    return $user;
}

it('creates branch registers and prevents duplicate codes in the same branch', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $user = cashRegisterUser($branch, 'manage branches');

    $this->actingAs($user)->postJson('/api/cash-registers', [
        'name' => 'Front Counter',
        'code' => 'FRONT',
        'location' => 'Ground floor',
    ])->assertCreated()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.is_active', true);

    $this->actingAs($user)->postJson('/api/cash-registers', [
        'name' => 'Second Counter',
        'code' => 'FRONT',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->assertDatabaseCount('cash_registers', 1);
});

it('opens a register session and refuses a second open session for the same register', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $user = cashRegisterUser($branch);

    $response = $this->actingAs($user)->postJson('/api/cash-register-sessions', [
        'cash_register_id' => $register->id,
        'opening_balance' => 500,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening_balance', '500.00')
        ->assertJsonPath('data.user_id', $user->id);
    $this->assertDatabaseHas('cash_register_sessions', [
        'id' => $response->json('data.id'),
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 500,
        'status' => 'open',
    ]);

    $this->actingAs($user)->postJson('/api/cash-register-sessions', [
        'cash_register_id' => $register->id,
        'opening_balance' => 100,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'This cash register already has an open session.');
});

it('reconciles cash in and out when closing a session', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $user = cashRegisterUser($branch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 100,
        'opened_at' => now(),
        'status' => 'open',
    ]);
    CashMovement::create([
        'cash_register_session_id' => $session->id,
        'user_id' => $user->id,
        'direction' => 'in',
        'amount' => 50,
        'description' => 'Cash deposit',
    ]);
    CashMovement::create([
        'cash_register_session_id' => $session->id,
        'user_id' => $user->id,
        'direction' => 'out',
        'amount' => 20,
        'description' => 'Cash withdrawal',
    ]);

    $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/close", [
        'closing_balance' => 125,
        'closing_note' => 'Counted by cashier',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.expected_balance', '130.00')
        ->assertJsonPath('data.closing_balance', '125.00')
        ->assertJsonPath('data.difference', '-5.00')
        ->assertJsonPath('data.closing_note', 'Counted by cashier');

    $this->assertDatabaseHas('cash_register_sessions', [
        'id' => $session->id,
        'status' => 'closed',
        'expected_balance' => 130,
        'difference' => -5,
        'closed_by' => $user->id,
    ]);
});

it('does not allow a cashier to read a session from another branch', function () {
    $mainBranch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTHER']);
    $register = CashRegister::create([
        'branch_id' => $otherBranch->id,
        'name' => 'Other Counter',
        'code' => 'OTHER-1',
    ]);
    $owner = cashRegisterUser($otherBranch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $owner->id,
        'opening_balance' => 0,
        'opened_at' => now(),
        'status' => 'open',
    ]);
    $user = cashRegisterUser($mainBranch);

    $this->actingAs($user)->getJson("/api/cash-register-sessions/{$session->id}")
        ->assertNotFound();
});

it('does not allow manual cash movements after a session is closed', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $user = cashRegisterUser($branch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'opened_at' => now(),
        'closed_at' => now(),
        'closing_balance' => 0,
        'expected_balance' => 0,
        'difference' => 0,
        'status' => 'closed',
    ]);

    $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/movements", [
        'direction' => 'in',
        'amount' => 25,
        'description' => 'Late deposit',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Cash movements can only be recorded for an open session.');

    $this->assertDatabaseCount('cash_movements', 0);
});

it('records manual cash movements in an open session', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $user = cashRegisterUser($branch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'opened_at' => now(),
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/movements", [
        'direction' => 'out',
        'amount' => 25,
        'description' => 'Petty cash expense',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.direction', 'out')
        ->assertJsonPath('data.category', 'manual')
        ->assertJsonPath('data.amount', '25.00')
        ->assertJsonPath('data.description', 'Petty cash expense');
    $this->assertDatabaseHas('cash_movements', [
        'id' => $response->json('data.id'),
        'cash_register_session_id' => $session->id,
        'user_id' => $user->id,
        'direction' => 'out',
        'amount' => 25,
    ]);
});

it('records cash sale receipts against the selected open register session', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    BranchStock::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);
    $user = cashRegisterUser($branch);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 100,
        'opened_at' => now(),
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)->postJson('/api/sales', [
        'invoice_number' => 'INV-CASH-001',
        'paid_amount' => 40,
        'payment_method' => 'cash',
        'cash_register_session_id' => $session->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.cash_register_id', $register->id)
        ->assertJsonPath('data.cash_register_session_id', $session->id);
    $this->assertDatabaseHas('cash_movements', [
        'cash_register_session_id' => $session->id,
        'direction' => 'in',
        'category' => 'sale_payment',
        'amount' => 40,
        'reference_type' => 'App\\Models\\Payment',
    ]);

    $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/close", [
        'closing_balance' => 140,
    ])->assertOk()
        ->assertJsonPath('data.expected_balance', '140.00')
        ->assertJsonPath('data.difference', '0.00');
});

it('records cash purchase payments as register outflows', function () {
    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $register = CashRegister::create([
        'branch_id' => $branch->id,
        'name' => 'Front Counter',
        'code' => 'FRONT',
    ]);
    $category = Category::create(['branch_id' => $branch->id, 'name' => 'Beverages']);
    $product = Product::create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'name' => 'Water',
        'sku' => 'WATER-001',
        'selling_price' => 20,
    ]);
    $supplier = Supplier::create(['branch_id' => $branch->id, 'name' => 'Water Supplier']);
    $user = cashRegisterUser($branch);
    $purchaseRole = Role::create(['name' => 'cash-register-purchaser', 'guard_name' => 'web']);
    $purchaseRole->givePermissionTo('manage purchases');
    $user->assignRole($purchaseRole);
    $session = CashRegisterSession::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opening_balance' => 100,
        'opened_at' => now(),
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PUR-CASH-001',
        'purchased_at' => '2026-10-13',
        'paid_amount' => 30,
        'payment_method' => 'cash',
        'cash_register_session_id' => $session->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 15]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.payment_status', 'paid');
    $this->assertDatabaseHas('cash_movements', [
        'cash_register_session_id' => $session->id,
        'direction' => 'out',
        'category' => 'purchase_payment',
        'amount' => 30,
    ]);

    $this->actingAs($user)->postJson("/api/cash-register-sessions/{$session->id}/close", [
        'closing_balance' => 70,
    ])->assertOk()
        ->assertJsonPath('data.expected_balance', '70.00')
        ->assertJsonPath('data.difference', '0.00');
});
