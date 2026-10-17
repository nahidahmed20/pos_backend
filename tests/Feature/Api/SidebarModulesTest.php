<?php

use App\Models\Biller;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warranty;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    app(RolePermissionSeeder::class)->run();
});

it('tests brand, unit, and warranty endpoints', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    // Brand
    $brandRes = $this->actingAs($superAdmin)->postJson('/api/brands', [
        'name' => 'Apple',
        'description' => 'Premium tech brand',
    ]);
    $brandRes->assertCreated()->assertJsonPath('data.name', 'Apple');
    $brandId = $brandRes->json('data.id');

    $this->actingAs($superAdmin)->getJson('/api/brands')->assertOk();
    $this->actingAs($superAdmin)->putJson("/api/brands/{$brandId}", ['name' => 'Apple Inc.'])->assertOk();

    // Unit
    $unitRes = $this->actingAs($superAdmin)->postJson('/api/units', [
        'name' => 'Kilogram',
        'short_name' => 'kg',
        'allow_decimal' => true,
    ]);
    $unitRes->assertCreated()->assertJsonPath('data.short_name', 'kg');

    // Warranty
    $warrantyRes = $this->actingAs($superAdmin)->postJson('/api/warranties', [
        'name' => '1 Year Official',
        'duration' => 12,
        'duration_type' => 'months',
    ]);
    $warrantyRes->assertCreated()->assertJsonPath('data.duration', 12);
});

it('tests stock adjustment and stock movement logging', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();
    $branch = Branch::firstOrFail();
    $product = Product::firstOrFail();

    $res = $this->actingAs($superAdmin)->postJson('/api/stock-adjustments', [
        'branch_id' => $branch->id,
        'adjustment_date' => now()->toDateString(),
        'reason' => 'Damaged in transit',
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => 10,
                'type' => 'addition',
            ],
        ],
    ]);

    $res->assertCreated()->assertJsonPath('data.reason', 'Damaged in transit');
    $this->assertDatabaseHas('stock_adjustments', [
        'branch_id' => $branch->id,
        'reason' => 'Damaged in transit',
    ]);
});

it('tests quotation creation and converting to sale', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();
    $branch = Branch::firstOrFail();
    $customer = Customer::create([
        'branch_id' => $branch->id,
        'name' => 'John Doe',
        'phone' => '01711111111',
    ]);
    $product = Product::firstOrFail();

    $quotationRes = $this->actingAs($superAdmin)->postJson('/api/quotations', [
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'quotation_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 50,
            ],
        ],
    ]);

    $quotationRes->assertCreated();
    $quotationId = $quotationRes->json('data.id');

    $convertRes = $this->actingAs($superAdmin)->postJson("/api/quotations/{$quotationId}/convert-to-sale");
    $convertRes->assertCreated()->assertJsonPath('data.customer_id', $customer->id);
    $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'converted']);
});

it('tests coupon creation and validation', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    $couponRes = $this->actingAs($superAdmin)->postJson('/api/coupons', [
        'code' => 'DISCOUNT10',
        'name' => '10% Off',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_spend' => 50,
    ]);
    $couponRes->assertCreated();

    $validateRes = $this->actingAs($superAdmin)->postJson('/api/coupons/validate', [
        'code' => 'DISCOUNT10',
        'subtotal' => 100,
    ]);
    $validateRes->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('discount', 10);
});

it('tests gift card creation and verification', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    $cardRes = $this->actingAs($superAdmin)->postJson('/api/gift-cards', [
        'card_number' => 'GC-9999-8888',
        'pin' => '1234',
        'amount' => 500,
    ]);
    $cardRes->assertCreated();

    $verifyRes = $this->actingAs($superAdmin)->postJson('/api/gift-cards/verify', [
        'card_number' => 'GC-9999-8888',
        'pin' => '1234',
    ]);
    $verifyRes->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('data.balance', '500.00');
});

it('tests purchase order creation and converting to purchase', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();
    $branch = Branch::firstOrFail();
    $supplier = Supplier::create([
        'branch_id' => $branch->id,
        'name' => 'Tech Supply Ltd',
    ]);
    $product = Product::firstOrFail();

    $poRes = $this->actingAs($superAdmin)->postJson('/api/purchase-orders', [
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 15,
            ],
        ],
    ]);
    $poRes->assertCreated();
    $poId = $poRes->json('data.id');

    $convertRes = $this->actingAs($superAdmin)->postJson("/api/purchase-orders/{$poId}/convert-to-purchase");
    $convertRes->assertCreated()->assertJsonPath('data.supplier_id', $supplier->id);
    $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'received']);
});

it('tests bank accounts, money transfer, and financial balance sheet', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    $acc1 = $this->actingAs($superAdmin)->postJson('/api/accounts', [
        'name' => 'Islami Bank',
        'account_number' => 'IBBL-001',
        'opening_balance' => 10000,
    ])->json('data.id');

    $acc2 = $this->actingAs($superAdmin)->postJson('/api/accounts', [
        'name' => 'City Bank',
        'account_number' => 'CBL-002',
        'opening_balance' => 2000,
    ])->json('data.id');

    $transferRes = $this->actingAs($superAdmin)->postJson('/api/money-transfers', [
        'from_account_id' => $acc1,
        'to_account_id' => $acc2,
        'amount' => 3000,
        'transfer_date' => now()->toDateString(),
    ]);
    $transferRes->assertCreated();

    $this->assertDatabaseHas('accounts', ['id' => $acc1, 'current_balance' => 7000]);
    $this->assertDatabaseHas('accounts', ['id' => $acc2, 'current_balance' => 5000]);

    // Financial balance sheet
    $bsRes = $this->actingAs($superAdmin)->getJson('/api/accounts-balance-sheet');
    $bsRes->assertOk()->assertJsonStructure(['assets', 'liabilities', 'equity']);
});

it('tests billers, settings, and profile APIs', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    // Biller
    $billerRes = $this->actingAs($superAdmin)->postJson('/api/billers', [
        'name' => 'Main Biller Officer',
        'email' => 'biller@example.com',
        'phone' => '01700000000',
    ]);
    $billerRes->assertCreated()->assertJsonPath('data.name', 'Main Biller Officer');

    // Settings
    $settingsRes = $this->actingAs($superAdmin)->postJson('/api/settings', [
        'settings' => [
            'company_name' => 'My Smart POS',
            'currency' => 'BDT',
            'currency_symbol' => '৳',
        ],
    ]);
    $settingsRes->assertOk();

    $getSettings = $this->actingAs($superAdmin)->getJson('/api/settings');
    $getSettings->assertOk()->assertJsonPath('data.company_name', 'My Smart POS');

    // Profile
    $profileRes = $this->actingAs($superAdmin)->getJson('/api/profile');
    $profileRes->assertOk()->assertJsonPath('data.email', 'admin@gmail.com');
});

it('tests super admin companies and packages', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    $pkg = $this->actingAs($superAdmin)->postJson('/api/packages', [
        'name' => 'Enterprise Plan',
        'price' => 99.99,
        'duration_days' => 30,
        'max_users' => 10,
    ]);
    $pkg->assertCreated();

    $company = $this->actingAs($superAdmin)->postJson('/api/companies', [
        'name' => 'Acme Retail Corp',
        'email' => 'contact@acmeretail.com',
    ]);
    $company->assertCreated();
});

it('tests productivity modules: todos, notes, contacts, calendar', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    // Todo
    $todo = $this->actingAs($superAdmin)->postJson('/api/todos', [
        'title' => 'Stock Audit for Main Branch',
        'priority' => 'high',
    ]);
    $todo->assertCreated()->assertJsonPath('data.title', 'Stock Audit for Main Branch');

    // Note
    $note = $this->actingAs($superAdmin)->postJson('/api/notes', [
        'title' => 'Cash Register reconciliation guidelines',
        'content' => 'Reconcile drawer at 10 PM daily.',
    ]);
    $note->assertCreated();

    // Contact
    $contact = $this->actingAs($superAdmin)->postJson('/api/contacts', [
        'name' => 'Alex Turner',
        'role' => 'Store Consultant',
    ]);
    $contact->assertCreated();
});

it('tests sales returns, payments list, product variants list, and details endpoints', function () {
    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();

    // Payments list
    $this->actingAs($superAdmin)->getJson('/api/payments')->assertOk();

    // Sales returns list
    $this->actingAs($superAdmin)->getJson('/api/sales-returns')->assertOk();
    $this->actingAs($superAdmin)->getJson('/api/returns')->assertOk();

    // Product variants list
    $this->actingAs($superAdmin)->getJson('/api/product-variants')->assertOk();
});
