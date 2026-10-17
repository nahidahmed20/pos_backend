<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function __construct(private InventoryBatchService $inventoryBatches) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePurchases($request);

        return response()->json(['data' => $this->queryFor($request)->with(['supplier:id,name', 'items.product:id,name,sku', 'items.variant', 'payments'])->latest()->paginate(25)]);
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        $this->authorizePurchases($request);

        $purchaseModel = $this->queryFor($request)
            ->with(['supplier', 'branch', 'items.product', 'items.variant', 'payments'])
            ->findOrFail($purchase);

        return response()->json(['data' => $purchaseModel]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePurchases($request);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'purchased_at' => ['required', 'date'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'paid_amount' => ['sometimes', 'numeric', 'min:0'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'in:cash,card,mobile_banking,bank_transfer,cheque'],
            'reference' => ['nullable', 'string', 'max:255'],
            'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.tax' => ['sometimes', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.manufactured_date' => ['nullable', 'date'],
            'items.*.expiry_date' => ['nullable', 'date', 'after_or_equal:items.*.manufactured_date'],
        ]);
        $branchId = $request->user()->branch_id;
        if ($request->user()->isSuperAdmin()) {
            $branchId = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']])['branch_id'];
        }
        abort_unless(Supplier::whereKey($data['supplier_id'])->where('branch_id', $branchId)->exists(), 422, 'Supplier does not belong to this branch.');

        $purchase = DB::transaction(function () use ($data, $branchId, $request): Purchase {
            $subtotal = collect($data['items'])->sum(fn (array $item): float => $item['quantity'] * $item['unit_cost']);
            $total = $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0);
            $paidAmount = $data['paid_amount'] ?? 0;
            abort_unless($paidAmount <= $total, 422, 'Paid amount cannot exceed total.');
            $dueAmount = $total - $paidAmount;
            $paymentStatus = $dueAmount <= 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');
            $paymentMethod = $data['payment_method'] ?? ($paidAmount > 0 ? 'cash' : null);
            $cashSession = null;
            if (! empty($data['cash_register_session_id'])) {
                abort_unless(
                    $paidAmount > 0 && $paymentMethod === 'cash',
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
            $purchase = Purchase::create([
                'branch_id' => $branchId,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $request->user()->id,
                'invoice_number' => $data['invoice_number'],
                'purchased_at' => $data['purchased_at'],
                'subtotal' => $subtotal,
                'discount' => $data['discount'] ?? 0,
                'tax' => $data['tax'] ?? 0,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'reference' => $data['reference'] ?? null,
                'status' => 'received',
                'received_at' => now(),
                'note' => $data['note'] ?? null,
            ]);
            if ($paidAmount > 0) {
                $payment = $purchase->payments()->create([
                    'branch_id' => $branchId,
                    'received_by' => $request->user()->id,
                    'amount' => $paidAmount,
                    'method' => $paymentMethod,
                    'reference' => $data['reference'] ?? null,
                    'paid_at' => now(),
                ]);
                if ($cashSession !== null) {
                    CashMovement::create([
                        'cash_register_session_id' => $cashSession->id,
                        'user_id' => $request->user()->id,
                        'direction' => 'out',
                        'category' => 'purchase_payment',
                        'amount' => $paidAmount,
                        'description' => "Payment for purchase {$purchase->invoice_number}",
                        'reference_type' => Payment::class,
                        'reference_id' => $payment->id,
                    ]);
                }
            }
            foreach ($data['items'] as $item) {
                $product = Product::whereKey($item['product_id'])->where('branch_id', $branchId)->firstOrFail();
                abort_unless(
                    ! $product->has_expiry || ! empty($item['expiry_date']),
                    422,
                    'An expiry date is required for expiry-tracked products.',
                );
                $variant = null;
                if (! empty($item['variant_id'])) {
                    $variant = ProductVariant::whereKey($item['variant_id'])
                        ->where('product_id', $product->id)
                        ->where('branch_id', $branchId)
                        ->firstOrFail();
                }
                $stock = BranchStock::firstOrCreate(
                    ['branch_id' => $branchId, 'product_id' => $product->id, 'variant_id' => $variant?->id],
                    ['quantity' => 0, 'reorder_level' => 0],
                );
                $stock = BranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $balanceAfter = (int) $stock->quantity + $item['quantity'];
                $stock->increment('quantity', $item['quantity']);
                $lineTotal = $item['quantity'] * $item['unit_cost'] - ($item['discount'] ?? 0) + ($item['tax'] ?? 0);
                $purchase->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                    'total' => $lineTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'manufactured_date' => $item['manufactured_date'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                ]);
                if ($product->has_expiry) {
                    $this->inventoryBatches->receive(
                        $branchId,
                        $product->id,
                        $variant?->id,
                        $item['quantity'],
                        $item['batch_number'] ?? null,
                        $item['manufactured_date'] ?? null,
                        $item['expiry_date'],
                        (float) $item['unit_cost'],
                    );
                }
                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'user_id' => $request->user()->id,
                    'quantity' => $item['quantity'],
                    'balance_after' => $balanceAfter,
                    'type' => 'purchase',
                    'unit_cost' => $item['unit_cost'],
                    'batch_number' => $item['batch_number'] ?? null,
                    'manufactured_date' => $item['manufactured_date'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                ]);
            }

            return $purchase;
        });

        return response()->json(['data' => $purchase->load(['supplier:id,name', 'items.product:id,name,sku', 'items.variant', 'payments'])], 201);
    }

    private function queryFor(Request $request): Builder
    {
        return Purchase::query()->when(! $request->user()->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id));
    }

    private function authorizePurchases(Request $request): void
    {
        abort_unless($request->user()?->can('manage purchases'), 403);
    }
}
