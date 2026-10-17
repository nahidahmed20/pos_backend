<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillerController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchStockController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\CashRegisterSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\DiscountPlanController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EcommerceController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FileManagerController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\IncomeCategoryController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\InventoryAlertController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MoneyTransferController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockBatchController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubscriptionPackageController;
use App\Http\Controllers\Api\SubscriptionTransactionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\VariantAttributeController;
use App\Http\Controllers\Api\WarrantyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/app-info', [SettingController::class, 'publicAppInfo']);

Route::middleware(['auth:sanctum'])->group(function (): void {
    // Auth & Profile
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // Branches & Cash Registers
    Route::apiResource('branches', BranchController::class)->except(['destroy']);
    Route::apiResource('cash-registers', CashRegisterController::class);
    Route::get('/cash-register-sessions', [CashRegisterSessionController::class, 'index']);
    Route::post('/cash-register-sessions', [CashRegisterSessionController::class, 'store']);
    Route::get('/cash-register-sessions/{cashRegisterSession}', [CashRegisterSessionController::class, 'show']);
    Route::post('/cash-register-sessions/{cashRegisterSession}/close', [CashRegisterSessionController::class, 'close']);
    Route::get('/cash-register-sessions/{cashRegisterSession}/movements', [CashMovementController::class, 'index']);
    Route::post('/cash-register-sessions/{cashRegisterSession}/movements', [CashMovementController::class, 'store']);

    // POS & Catalog
    Route::get('/pos/catalog', [SaleController::class, 'catalog']);

    // Inventory & Catalog
    Route::apiResource('categories', CategoryController::class);
    Route::get('/sub-categories', [InventoryAlertController::class, 'subCategories']);
    Route::apiResource('products', ProductController::class);
    Route::get('/product-variants', [ProductVariantController::class, 'allVariants']);
    Route::get('/product-variants/{variant}', [ProductVariantController::class, 'showDirect']);
    Route::put('/product-variants/{variant}', [ProductVariantController::class, 'updateDirect']);
    Route::patch('/product-variants/{variant}', [ProductVariantController::class, 'updateDirect']);
    Route::delete('/product-variants/{variant}', [ProductVariantController::class, 'destroyDirect']);
    Route::apiResource('products.variants', ProductVariantController::class)->except(['create', 'edit']);
    Route::apiResource('brands', BrandController::class);
    Route::apiResource('units', UnitController::class);
    Route::apiResource('warranties', WarrantyController::class);
    Route::apiResource('variant-attributes', VariantAttributeController::class);
    Route::post('/variant-attributes/{variantAttribute}/values', [VariantAttributeController::class, 'storeValue']);
    Route::delete('/variant-attributes/{variantAttribute}/values/{value}', [VariantAttributeController::class, 'destroyValue']);

    // Inventory Alerts & Print
    Route::get('/inventory/expired-products', [InventoryAlertController::class, 'expiredProducts']);
    Route::get('/inventory/low-stocks', [InventoryAlertController::class, 'lowStocks']);
    Route::get('/inventory/barcode-print', [InventoryAlertController::class, 'barcodePrint']);
    Route::get('/inventory/qrcode-print', [InventoryAlertController::class, 'qrcodePrint']);

    // Stock Management & Transfers
    Route::get('/stocks', [BranchStockController::class, 'index']);
    Route::put('/products/{product}/stock', [BranchStockController::class, 'update']);
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::post('/stock-movements', [StockMovementController::class, 'store']);
    Route::get('/stock-batches', [StockBatchController::class, 'index']);
    Route::post('/stock-batches/opening', [StockBatchController::class, 'registerOpening']);
    Route::get('/stock-transfers', [StockTransferController::class, 'index']);
    Route::post('/stock-transfers', [StockTransferController::class, 'store']);
    Route::apiResource('stock-adjustments', StockAdjustmentController::class)->except(['update']);

    // Purchases
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/payments', [PaymentController::class, 'storeForPurchase']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('/purchase-orders/{purchaseOrder}/convert-to-purchase', [PurchaseOrderController::class, 'convertToPurchase']);
    Route::apiResource('purchase-returns', PurchaseReturnController::class);

    // Sales & POS
    Route::apiResource('customers', CustomerController::class);
    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/sales/{sale}/payments', [PaymentController::class, 'store']);
    Route::get('/sales-returns', [SaleReturnController::class, 'index']);
    Route::get('/returns', [SaleReturnController::class, 'index']);
    Route::get('/sales-returns/{saleReturn}', [SaleReturnController::class, 'show']);
    Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store']);
    Route::apiResource('quotations', QuotationController::class);
    Route::post('/quotations/{quotation}/convert-to-sale', [QuotationController::class, 'convertToSale']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{sale}', [InvoiceController::class, 'show']);

    // Promo
    Route::apiResource('coupons', CouponController::class);
    Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);
    Route::apiResource('gift-cards', GiftCardController::class);
    Route::post('/gift-cards/verify', [GiftCardController::class, 'verify']);
    Route::apiResource('discount-plans', DiscountPlanController::class);
    Route::apiResource('discounts', DiscountController::class);

    // Finance & Accounts
    Route::apiResource('expenses', ExpenseController::class);
    Route::apiResource('expense-categories', ExpenseCategoryController::class);
    Route::apiResource('income-categories', IncomeCategoryController::class);
    Route::apiResource('incomes', IncomeController::class);
    Route::apiResource('accounts', AccountController::class);
    Route::get('/accounts/{account}/statement', [AccountController::class, 'statement']);
    Route::get('/accounts-balance-sheet', [AccountController::class, 'balanceSheet']);
    Route::get('/accounts-trial-balance', [AccountController::class, 'trialBalance']);
    Route::get('/accounts-cash-flow', [AccountController::class, 'cashFlow']);
    Route::apiResource('money-transfers', MoneyTransferController::class)->except(['update']);

    // Peoples
    Route::apiResource('billers', BillerController::class);

    // Reports
    Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);

    // Access Control
    Route::get('/access-control', [AccessControlController::class, 'index']);
    Route::get('/permissions', [AccessControlController::class, 'permissions']);
    Route::post('/permissions', [AccessControlController::class, 'storePermission']);
    Route::get('/roles', [AccessControlController::class, 'roles']);
    Route::post('/roles', [AccessControlController::class, 'storeRole']);
    Route::get('/users', [AccessControlController::class, 'users']);
    Route::post('/users', [AccessControlController::class, 'storeUser']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/settings', [SettingController::class, 'update']);

    // Super Admin / SaaS
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('packages', SubscriptionPackageController::class);
    Route::apiResource('subscriptions', SubscriptionController::class);
    Route::apiResource('domains', DomainController::class);
    Route::apiResource('purchase-transactions', SubscriptionTransactionController::class)->only(['index', 'store', 'show', 'destroy']);

    // Application Modules
    Route::apiResource('todos', TodoController::class);
    Route::apiResource('notes', NoteController::class);
    Route::apiResource('contacts', ContactController::class);
    Route::apiResource('calendar-events', CalendarEventController::class);
    Route::apiResource('call-logs', CallLogController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::get('/chat/messages', [ChatMessageController::class, 'index']);
    Route::post('/chat/messages', [ChatMessageController::class, 'store']);
    Route::get('/files', [FileManagerController::class, 'index']);
    Route::post('/files', [FileManagerController::class, 'store']);
    Route::delete('/files/{file}', [FileManagerController::class, 'destroy']);
    Route::apiResource('projects', ProjectController::class);
    Route::get('/ecommerce/orders', [EcommerceController::class, 'orders']);
    Route::get('/ecommerce/reviews', [EcommerceController::class, 'reviews']);
    Route::post('/ecommerce/reviews', [EcommerceController::class, 'storeReview']);
    Route::put('/ecommerce/reviews/{review}', [EcommerceController::class, 'updateReview']);
    Route::delete('/ecommerce/reviews/{review}', [EcommerceController::class, 'destroyReview']);
    Route::get('/emails', [EmailController::class, 'index']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
