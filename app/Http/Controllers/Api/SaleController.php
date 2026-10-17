<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(private InventoryBatchService $inventoryBatches) {}

    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'purpose' => ['sometimes', 'string', 'in:sale,purchase'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $purpose = $data['purpose'] ?? 'sale';
        abort_unless(
            $purpose === 'sale'
                ? $request->user()->can('manage pos')
                : $request->user()->can('manage purchases'),
            403,
        );
        $branchId = $request->user()->isSuperAdmin()
            ? ($data['branch_id'] ?? null)
            : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'A branch is required to browse the POS catalog.');

        $perPage = (int) $request->query('per_page', 250);

        $catalog = Product::query()
            ->where(function (Builder $query) use ($branchId): void {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id')
                    ->orWhereHas('stock', fn (Builder $stock) => $stock->where('branch_id', $branchId))
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->where('branch_id', $branchId));
            })
            ->where('is_active', true)
            ->where($purpose === 'sale' ? 'is_sellable' : 'is_purchasable', true)
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)
                        ->orWhere('secondary_barcode', $search)
                        ->orWhereHas('variants', fn (Builder $variants) => $variants
                            ->where('is_active', true)
                            ->where(function (Builder $variants) use ($search): void {
                                $variants->where('sku', 'like', "%{$search}%")
                                    ->orWhere('barcode', $search)
                                    ->orWhere('name', 'like', "%{$search}%");
                            }));
                });
            })
            ->with([
                'category:id,name',
                'stock' => fn (HasOne $query) => $query
                    ->where('branch_id', $branchId)
                    ->whereNull('variant_id'),
                'variants' => fn (HasMany $query) => $query
                    ->where('is_active', true)
                    ->with(['stock' => fn (HasOne $stock) => $stock->where('branch_id', $branchId)])
                    ->withSum([
                        'inventoryBatches as sellable_batch_quantity' => fn (Builder $batches) => $batches
                            ->where('branch_id', $branchId)
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '>=', today()),
                    ], 'quantity'),
            ])
            ->withSum([
                'inventoryBatches as sellable_batch_quantity' => fn (Builder $batches) => $batches
                    ->where('branch_id', $branchId)
                    ->whereNull('variant_id')
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', today()),
            ], 'quantity')
            ->orderBy('name')
            ->paginate($perPage);

        $catalog->getCollection()->each(function (Product $product): void {
            $baseStock = (int) ($product->stock?->quantity ?? 0);
            $product->setAttribute(
                'sellable_quantity',
                $product->has_expiry ? (int) $product->sellable_batch_quantity : ($product->track_stock ? $baseStock : 999),
            );
            foreach ($product->variants as $variant) {
                $vStock = (int) ($variant->stock?->quantity ?? 0);
                $variant->setAttribute(
                    'sellable_quantity',
                    $product->has_expiry ? (int) $variant->sellable_batch_quantity : ($product->track_stock ? $vStock : 999),
                );
            }
        });

        return response()->json(['data' => $catalog]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePos($request);
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_status' => ['nullable', 'string', 'in:unpaid,partial,paid'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sales = $this->queryFor($request, $filters['branch_id'] ?? null)
            ->when($filters['invoice_number'] ?? null, fn (Builder $query, string $invoice) => $query->where('invoice_number', 'like', "%{$invoice}%"))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('payment_status', $status))
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sold_at', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sold_at', '<=', $date))
            ->with([
                'customer:id,name,phone',
                'items.product:id,name,sku',
                'items.variant',
                'items.batchAllocations.inventoryBatch',
                'payments',
                'returns.items.batchAllocations.inventoryBatch',
            ])
            ->latest()
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return response()->json(['data' => $sales]);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $this->authorizePos($request);

        $saleModel = $this->queryFor($request)
            ->with([
                'branch',
                'customer',
                'items.product',
                'items.variant',
                'items.batchAllocations.inventoryBatch',
                'payments',
                'returns.items',
            ])
            ->findOrFail($sale);

        return response()->json(['data' => $saleModel]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'paid_amount' => ['sometimes', 'numeric', 'min:0'],
            'payment_method' => ['required_with:paid_amount', 'nullable', 'string', 'in:cash,card,mobile_banking,due'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $lineKeys = collect($data['items'])
            ->map(fn (array $item): string => $item['product_id'].':'.($item['variant_id'] ?? 'base'));
        abort_if($lineKeys->duplicates()->isNotEmpty(), 422, 'A product variant can only appear once in a sale.');
        $branchId = $request->user()->branch_id;
        abort_unless($branchId !== null || $request->user()->isSuperAdmin(), 422);
        if ($request->user()->isSuperAdmin()) {
            $branchId = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']])['branch_id'];
        }
        if (! empty($data['customer_id'])) {
            abort_unless(Customer::whereKey($data['customer_id'])->where('branch_id', $branchId)->exists(), 422, 'Customer does not belong to this branch.');
        }

        $sale = DB::transaction(function () use ($data, $branchId, $request): Sale {
            $cashSession = null;
            if (! empty($data['cash_register_session_id'])) {
                abort_unless(
                    ($data['payment_method'] ?? null) === 'cash' && ($data['paid_amount'] ?? 0) > 0,
                    422,
                    'An open cash session can only be attached to a cash payment.',
                );
                $cashSession = CashRegisterSession::query()
                    ->whereKey($data['cash_register_session_id'])
                    ->where('status', 'open')
                    ->whereHas('cashRegister', fn (Builder $query) => $query->where('branch_id', $branchId))
                    ->when(
                        ! $request->user()->isSuperAdmin() && ! $request->user()->can('manage branches'),
                        fn (Builder $query) => $query->where('user_id', $request->user()->id),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $items = [];
            foreach ($data['items'] as $item) {
                $product = Product::query()
                    ->whereKey($item['product_id'])
                    ->where(function (Builder $query) use ($branchId, $item): void {
                        $query->where('branch_id', $branchId)
                            ->orWhereNull('branch_id')
                            ->orWhereHas('stock', fn (Builder $stock) => $stock
                                ->where('branch_id', $branchId)
                                ->where('variant_id', $item['variant_id'] ?? null))
                            ->orWhereHas('variants', fn (Builder $variants) => $variants
                                ->where('branch_id', $branchId));
                    })
                    ->where('is_active', true)
                    ->where('is_sellable', true)
                    ->firstOrFail();
                $variant = null;
                if (! empty($item['variant_id'])) {
                    $variant = ProductVariant::query()
                        ->whereKey($item['variant_id'])
                        ->where('product_id', $product->id)
                        ->where('is_active', true)
                        ->firstOrFail();
                }
                $stock = BranchStock::query()
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->where('variant_id', $variant?->id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    $stock = BranchStock::firstOrCreate([
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                    ], [
                        'quantity' => 0,
                    ]);
                }

                if ($product->track_stock && ! $product->allow_negative_stock) {
                    abort_unless($stock->quantity >= $item['quantity'], 422, "Insufficient stock for {$product->name}".($variant ? " ({$variant->name})" : '').'.');
                }
                $batchAllocations = $product->has_expiry
                    ? $this->inventoryBatches->takeForSale($branchId, $product->id, $variant?->id, $item['quantity'])
                    : [];
                $unitPrice = (float) ($variant?->selling_price ?? $product->selling_price);
                $unitCost = (float) ($variant?->purchase_price ?? $product->purchase_price);
                $items[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'stock' => $stock,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'total' => $item['quantity'] * $unitPrice,
                    'batch_allocations' => $batchAllocations,
                ];
            }
            $subtotal = collect($items)->sum('total');
            $total = $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0);
            $paid = $data['paid_amount'] ?? 0;
            abort_unless($paid <= $total, 422, 'Paid amount cannot exceed total.');
            $due = $total - $paid;
            $paymentStatus = $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
            $sale = Sale::create(['branch_id' => $branchId, 'customer_id' => $data['customer_id'] ?? null, 'cash_register_id' => $cashSession?->cash_register_id, 'cash_register_session_id' => $cashSession?->id, 'created_by' => $request->user()->id, 'invoice_number' => $data['invoice_number'], 'sold_at' => now(), 'subtotal' => $subtotal, 'discount' => $data['discount'] ?? 0, 'tax' => $data['tax'] ?? 0, 'total' => $total, 'paid_amount' => $paid, 'due_amount' => $due, 'payment_status' => $paymentStatus, 'payment_method' => $data['payment_method'] ?? null]);
            foreach ($items as $item) {
                $balanceAfter = (int) $item['stock']->quantity - $item['quantity'];
                $singleBatch = count($item['batch_allocations']) === 1
                    ? $item['batch_allocations'][0]['batch']
                    : null;
                $saleItem = $sale->items()->create([
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'cost_price' => $item['unit_cost'],
                    'total' => $item['total'],
                    'batch_number' => $singleBatch?->batch_number,
                    'expiry_date' => $singleBatch?->expiry_date,
                ]);
                foreach ($item['batch_allocations'] as $allocation) {
                    $saleItem->batchAllocations()->create([
                        'inventory_batch_id' => $allocation['batch']->id,
                        'quantity' => $allocation['quantity'],
                    ]);
                }
                $item['stock']->update(['quantity' => $balanceAfter]);
                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $item['product']->id,
                    'variant_id' => $item['variant']?->id,
                    'user_id' => $request->user()->id,
                    'quantity' => -$item['quantity'],
                    'balance_after' => $balanceAfter,
                    'type' => 'sale',
                    'batch_number' => $singleBatch?->batch_number,
                    'expiry_date' => $singleBatch?->expiry_date,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                ]);
            }
            if ($paid > 0) {
                $payment = Payment::create(['sale_id' => $sale->id, 'branch_id' => $branchId, 'customer_id' => $sale->customer_id, 'received_by' => $request->user()->id, 'amount' => $paid, 'method' => $data['payment_method'], 'paid_at' => now()]);
                if ($cashSession !== null) {
                    CashMovement::create([
                        'cash_register_session_id' => $cashSession->id,
                        'user_id' => $request->user()->id,
                        'direction' => 'in',
                        'category' => 'sale_payment',
                        'amount' => $paid,
                        'description' => "Payment for sale {$sale->invoice_number}",
                        'reference_type' => Payment::class,
                        'reference_id' => $payment->id,
                    ]);
                }
            }

            return $sale;
        });

        return response()->json([
            'data' => $sale->load([
                'customer:id,name,phone',
                'items.product:id,name,sku',
                'items.variant',
                'items.batchAllocations.inventoryBatch',
                'payments',
            ]),
        ], 201);
    }

    private function queryFor(Request $request, ?int $branchId = null): Builder
    {
        return Sale::query()
            ->when(
                ! $request->user()->isSuperAdmin(),
                fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id),
                fn (Builder $query) => $query->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId)),
            );
    }

    private function authorizePos(Request $request): void
    {
        abort_unless($request->user()?->can('manage pos'), 403);
    }
}
