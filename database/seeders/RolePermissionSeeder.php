<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Biller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\SubscriptionPackage;
use App\Models\Unit;
use App\Models\User;
use App\Models\VariantAttribute;
use App\Models\Warranty;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'manage pos',
            'manage ecommerce',
            'manage branches',
            'manage users',
            'manage products',
            'manage purchases',
            'manage expenses',
            'view reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo(Permission::all());

        $branchManager = Role::firstOrCreate(['name' => 'branch-manager', 'guard_name' => 'web']);
        $branchManager->givePermissionTo(['manage pos', 'manage products', 'manage purchases', 'manage expenses', 'view reports']);

        $cashier = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['manage pos']);

        $mainBranch = Branch::firstOrCreate(['code' => 'MAIN'], [
            'name' => 'Main Branch',
        ]);
        $uttaraBranch = Branch::firstOrCreate(['code' => 'UTTARA'], [
            'name' => 'Uttara Branch',
        ]);

        $this->seedSampleCatalog($mainBranch, 'MAIN');
        $this->seedSampleCatalog($uttaraBranch, 'UTTARA');
        $this->seedDefaultModules($mainBranch);

        $adminUser = User::firstOrCreate(['email' => 'admin@gmail.com'], [
            'name' => 'Super Admin',
            'password' => Hash::make('password123'),
        ]);

        $adminUser->update(['branch_id' => null]);
        $adminUser->syncRoles(['super-admin']);

        $branchAdmin = User::firstOrCreate(['email' => 'branchadmin@gmail.com'], [
            'name' => 'Branch Admin',
            'password' => Hash::make('password123'),
        ]);

        $branchAdmin->update(['branch_id' => $mainBranch->id]);
        $branchAdmin->syncRoles(['admin']);
    }

    private function seedSampleCatalog(Branch $branch, string $prefix): void
    {
        // 1. Beverages Category & Mineral Water (Standard)
        $bevCat = Category::firstOrCreate([
            'branch_id' => $branch->id,
            'name' => 'Beverages',
        ]);
        $waterCat = Category::firstOrCreate([
            'branch_id' => $branch->id,
            'name' => 'Packaged Water',
        ], [
            'parent_id' => $bevCat->id,
        ]);

        $water = Product::firstOrCreate([
            'branch_id' => $branch->id,
            'sku' => "{$prefix}-WATER-001",
        ], [
            'category_id' => $waterCat->id,
            'brand' => 'Fresh Water',
            'name' => 'Mineral Water 500ml',
            'barcode' => '8901001001',
            'unit' => 'pcs',
            'product_type' => 'standard',
            'purchase_price' => 12,
            'selling_price' => 20,
            'is_sellable' => true,
            'is_active' => true,
            'track_stock' => true,
        ]);

        BranchStock::updateOrCreate(
            ['branch_id' => $branch->id, 'product_id' => $water->id, 'variant_id' => null],
            ['quantity' => 150, 'reorder_level' => 10],
        );

        // 2. Apparel Category & Classic Cotton Polo T-Shirt (Variable Product)
        $apparelCat = Category::firstOrCreate([
            'branch_id' => $branch->id,
            'name' => 'Apparel & Fashion',
        ]);

        $polo = Product::firstOrCreate([
            'branch_id' => $branch->id,
            'sku' => "{$prefix}-POLO-BASE",
        ], [
            'category_id' => $apparelCat->id,
            'brand' => 'Polo Club',
            'name' => 'Classic Cotton Polo T-Shirt',
            'barcode' => '8902002000',
            'unit' => 'pcs',
            'product_type' => 'variable',
            'purchase_price' => 350,
            'selling_price' => 650,
            'is_sellable' => true,
            'is_active' => true,
            'track_stock' => true,
        ]);

        $poloVariants = [
            [
                'name' => 'Black / M',
                'sku' => "{$prefix}-POLO-BLK-M",
                'barcode' => '8902002001',
                'purchase_price' => 350,
                'selling_price' => 650,
                'attributes' => ['Color' => 'Black', 'Size' => 'M'],
                'stock' => 25,
            ],
            [
                'name' => 'Black / L',
                'sku' => "{$prefix}-POLO-BLK-L",
                'barcode' => '8902002002',
                'purchase_price' => 350,
                'selling_price' => 650,
                'attributes' => ['Color' => 'Black', 'Size' => 'L'],
                'stock' => 30,
            ],
            [
                'name' => 'Navy Blue / L',
                'sku' => "{$prefix}-POLO-NVY-L",
                'barcode' => '8902002003',
                'purchase_price' => 370,
                'selling_price' => 680,
                'attributes' => ['Color' => 'Navy Blue', 'Size' => 'L'],
                'stock' => 20,
            ],
            [
                'name' => 'Crimson Red / XL',
                'sku' => "{$prefix}-POLO-RED-XL",
                'barcode' => '8902002004',
                'purchase_price' => 380,
                'selling_price' => 700,
                'attributes' => ['Color' => 'Crimson Red', 'Size' => 'XL'],
                'stock' => 15,
            ],
        ];

        foreach ($poloVariants as $pv) {
            $variant = ProductVariant::firstOrCreate([
                'branch_id' => $branch->id,
                'product_id' => $polo->id,
                'sku' => $pv['sku'],
            ], [
                'name' => $pv['name'],
                'barcode' => $pv['barcode'],
                'purchase_price' => $pv['purchase_price'],
                'selling_price' => $pv['selling_price'],
                'attributes' => $pv['attributes'],
                'is_active' => true,
            ]);

            BranchStock::updateOrCreate(
                ['branch_id' => $branch->id, 'product_id' => $polo->id, 'variant_id' => $variant->id],
                ['quantity' => $pv['stock'], 'reorder_level' => 5],
            );
        }

        // 3. Gadgets Category & Wireless Bluetooth Earbuds Pro (Variable Product)
        $gadgetCat = Category::firstOrCreate([
            'branch_id' => $branch->id,
            'name' => 'Electronics & Gadgets',
        ]);

        $earbuds = Product::firstOrCreate([
            'branch_id' => $branch->id,
            'sku' => "{$prefix}-EAR-BASE",
        ], [
            'category_id' => $gadgetCat->id,
            'brand' => 'Samsung',
            'name' => 'Wireless Bluetooth Earbuds Pro',
            'barcode' => '8903003000',
            'unit' => 'pcs',
            'product_type' => 'variable',
            'purchase_price' => 900,
            'selling_price' => 1450,
            'is_sellable' => true,
            'is_active' => true,
            'track_stock' => true,
        ]);

        $earbudVariants = [
            [
                'name' => 'Glacier White',
                'sku' => "{$prefix}-EAR-WHT",
                'barcode' => '8903003001',
                'purchase_price' => 900,
                'selling_price' => 1450,
                'attributes' => ['Color' => 'White', 'Edition' => 'Glossy'],
                'stock' => 18,
            ],
            [
                'name' => 'Matte Midnight Black',
                'sku' => "{$prefix}-EAR-BLK",
                'barcode' => '8903003002',
                'purchase_price' => 950,
                'selling_price' => 1550,
                'attributes' => ['Color' => 'Black', 'Edition' => 'Matte Pro'],
                'stock' => 24,
            ],
        ];

        foreach ($earbudVariants as $ev) {
            $variant = ProductVariant::firstOrCreate([
                'branch_id' => $branch->id,
                'product_id' => $earbuds->id,
                'sku' => $ev['sku'],
            ], [
                'name' => $ev['name'],
                'barcode' => $ev['barcode'],
                'purchase_price' => $ev['purchase_price'],
                'selling_price' => $ev['selling_price'],
                'attributes' => $ev['attributes'],
                'is_active' => true,
            ]);

            BranchStock::updateOrCreate(
                ['branch_id' => $branch->id, 'product_id' => $earbuds->id, 'variant_id' => $variant->id],
                ['quantity' => $ev['stock'], 'reorder_level' => 5],
            );
        }

        // 4. Standard Product: Wireless Optical Mouse
        $mouse = Product::firstOrCreate([
            'branch_id' => $branch->id,
            'sku' => "{$prefix}-MOUSE-001",
        ], [
            'category_id' => $gadgetCat->id,
            'brand' => 'Dell',
            'name' => 'Wireless Optical Mouse',
            'barcode' => '8904004001',
            'unit' => 'pcs',
            'product_type' => 'standard',
            'purchase_price' => 300,
            'selling_price' => 450,
            'is_sellable' => true,
            'is_active' => true,
            'track_stock' => true,
        ]);

        BranchStock::updateOrCreate(
            ['branch_id' => $branch->id, 'product_id' => $mouse->id, 'variant_id' => null],
            ['quantity' => 60, 'reorder_level' => 10],
        );
    }

    private function seedDefaultModules(Branch $branch): void
    {
        // Brands
        foreach (['Apple', 'Samsung', 'Sony', 'HP', 'Dell'] as $bName) {
            Brand::firstOrCreate(['name' => $bName], ['branch_id' => $branch->id, 'slug' => Str::slug($bName)]);
        }

        // Units
        $units = [
            ['name' => 'Pieces', 'short_name' => 'pcs', 'allow_decimal' => false],
            ['name' => 'Kilogram', 'short_name' => 'kg', 'allow_decimal' => true],
            ['name' => 'Liter', 'short_name' => 'ltr', 'allow_decimal' => true],
            ['name' => 'Box', 'short_name' => 'box', 'allow_decimal' => false],
        ];
        foreach ($units as $u) {
            Unit::firstOrCreate(['name' => $u['name']], [...$u, 'branch_id' => $branch->id]);
        }

        // Warranties
        $warranties = [
            ['name' => 'No Warranty', 'duration' => 0, 'duration_type' => 'months'],
            ['name' => '6 Months Standard', 'duration' => 6, 'duration_type' => 'months'],
            ['name' => '1 Year Official', 'duration' => 12, 'duration_type' => 'months'],
            ['name' => '2 Years Official', 'duration' => 24, 'duration_type' => 'months'],
        ];
        foreach ($warranties as $w) {
            Warranty::firstOrCreate(['name' => $w['name']], [...$w, 'branch_id' => $branch->id]);
        }

        // Variant Attributes
        $sizeAttr = VariantAttribute::firstOrCreate(['name' => 'Size'], ['code' => 'SIZE', 'branch_id' => $branch->id]);
        foreach (['S', 'M', 'L', 'XL'] as $size) {
            $sizeAttr->values()->firstOrCreate(['value' => $size]);
        }
        $colorAttr = VariantAttribute::firstOrCreate(['name' => 'Color'], ['code' => 'COLOR', 'branch_id' => $branch->id]);
        foreach (['Black', 'White', 'Blue', 'Red'] as $color) {
            $colorAttr->values()->firstOrCreate(['value' => $color]);
        }

        // Expense Categories
        foreach (['Rent & Utilities', 'Office Supplies', 'Staff Salary', 'Marketing', 'Maintenance'] as $expName) {
            ExpenseCategory::firstOrCreate(['name' => $expName], ['branch_id' => $branch->id]);
        }

        // Income Categories
        foreach (['Direct Sales', 'Service Fee', 'Delivery Charge', 'Miscellaneous'] as $incName) {
            IncomeCategory::firstOrCreate(['name' => $incName], ['branch_id' => $branch->id]);
        }

        // Accounts
        Account::firstOrCreate(['name' => 'Cash Drawer'], [
            'branch_id' => $branch->id,
            'opening_balance' => 5000,
            'current_balance' => 5000,
        ]);
        Account::firstOrCreate(['name' => 'Bank Account'], [
            'branch_id' => $branch->id,
            'bank_name' => 'City Bank Ltd.',
            'account_number' => '1002003004',
            'opening_balance' => 50000,
            'current_balance' => 50000,
        ]);

        // Billers
        Biller::firstOrCreate(['name' => 'Main Branch Biller'], [
            'branch_id' => $branch->id,
            'email' => 'biller@pos.local',
            'phone' => '01700000000',
        ]);

        // Default Coupons
        Coupon::firstOrCreate(['code' => 'WELCOME10'], [
            'name' => 'Welcome 10% Discount',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_spend' => 100,
            'branch_id' => $branch->id,
        ]);

        // System Settings
        $defaults = [
            'company_name' => 'My POS Store',
            'phone' => '+880 1700-000000',
            'email' => 'store@example.com',
            'address' => 'Dhaka, Bangladesh',
            'currency' => 'BDT',
            'currency_symbol' => '৳',
            'invoice_prefix' => 'INV-',
            'receipt_header' => 'Thank you for shopping with us!',
            'receipt_footer' => 'Please visit us again!',
        ];
        foreach ($defaults as $k => $v) {
            Setting::firstOrCreate(
                ['branch_id' => $branch->id, 'key' => $k],
                ['value' => $v, 'group' => 'general']
            );
        }

        // SaaS packages
        SubscriptionPackage::firstOrCreate(['name' => 'Starter Plan'], [
            'price' => 29,
            'duration_days' => 30,
            'max_users' => 3,
            'max_branches' => 1,
            'max_products' => 500,
        ]);
        SubscriptionPackage::firstOrCreate(['name' => 'Business Pro Plan'], [
            'price' => 79,
            'duration_days' => 30,
            'max_users' => 10,
            'max_branches' => 3,
            'max_products' => 5000,
        ]);
    }
}
