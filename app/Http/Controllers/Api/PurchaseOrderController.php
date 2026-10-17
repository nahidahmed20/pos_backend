<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)
                ->with(['supplier:id,name,phone,email', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);

        $order = DB::transaction(function () use ($data, $branchId, $request) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_cost'];
                $itemTax = $item['tax'] ?? 0;
                $itemTotal = $itemSubtotal + $itemTax;
                $subtotal += $itemSubtotal;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => $itemTotal,
                ];
            }

            $discount = $data['discount'] ?? 0;
            $tax = $data['tax'] ?? 0;
            $total = $subtotal - $discount + $tax;

            $order = PurchaseOrder::create([
                'branch_id' => $branchId,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $request->user()->id,
                'po_number' => 'PO-'.strtoupper(Str::random(8)),
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemsData as $itemRow) {
                $order->items()->create($itemRow);
            }

            return $order;
        });

        return response()->json([
            'data' => $order->load(['supplier:id,name,phone', 'items.product:id,name,sku', 'items.variant:id,name,sku']),
        ], 201);
    }

    public function show(Request $request, int $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $purchaseOrder)
                ->load(['supplier:id,name,phone,email,address', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name']),
        ]);
    }

    public function update(Request $request, int $purchaseOrder): JsonResponse
    {
        $model = $this->findFor($request, $purchaseOrder);
        $data = $request->validate([
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:pending,ordered,received,cancelled'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('supplier:id,name,phone')]);
    }

    public function destroy(Request $request, int $purchaseOrder): JsonResponse
    {
        $model = $this->findFor($request, $purchaseOrder);
        $model->items()->delete();
        $model->delete();

        return response()->json(['message' => 'Purchase order deleted successfully.']);
    }

    public function convertToPurchase(Request $request, int $purchaseOrder): JsonResponse
    {
        $model = $this->findFor($request, $purchaseOrder)->load('items');
        abort_if($model->status === 'received', 422, 'Purchase order has already been received/converted.');

        $purchase = DB::transaction(function () use ($model, $request) {
            $purchase = Purchase::create([
                'branch_id' => $model->branch_id,
                'supplier_id' => $model->supplier_id,
                'created_by' => $request->user()->id,
                'invoice_number' => 'PUR-'.strtoupper(Str::random(8)),
                'purchased_at' => now(),
                'subtotal' => $model->subtotal,
                'discount' => $model->discount,
                'tax' => $model->tax,
                'total' => $model->total,
                'paid_amount' => 0,
                'due_amount' => $model->total,
                'payment_status' => 'due',
                'status' => 'pending',
                'note' => "Generated from PO: {$model->po_number}",
            ]);

            foreach ($model->items as $item) {
                $purchase->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                    'subtotal' => $item->subtotal,
                    'tax' => $item->tax,
                    'total' => $item->total,
                ]);
            }

            $model->update(['status' => 'received']);

            return $purchase;
        });

        return response()->json([
            'message' => 'Purchase order converted to purchase successfully.',
            'data' => $purchase->load(['items.product:id,name,sku', 'supplier:id,name']),
        ], 201);
    }

    private function queryFor(Request $request): Builder
    {
        return PurchaseOrder::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): PurchaseOrder
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): int
    {
        $branchId = $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'Branch ID is required.');

        return $branchId;
    }
}
