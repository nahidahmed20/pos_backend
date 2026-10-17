<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)
                ->with(['customer:id,name,phone,email', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.tax' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);

        $quotation = DB::transaction(function () use ($data, $branchId, $request) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemDiscount = $item['discount'] ?? 0;
                $itemTax = $item['tax'] ?? 0;
                $itemTotal = $itemSubtotal - $itemDiscount + $itemTax;
                $subtotal += $itemSubtotal;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $itemSubtotal,
                    'discount' => $itemDiscount,
                    'tax' => $itemTax,
                    'total' => $itemTotal,
                ];
            }

            $discount = $data['discount'] ?? 0;
            $tax = $data['tax'] ?? 0;
            $total = $subtotal - $discount + $tax;

            $quotation = Quotation::create([
                'branch_id' => $branchId,
                'customer_id' => $data['customer_id'] ?? null,
                'created_by' => $request->user()->id,
                'reference_number' => 'QTN-'.strtoupper(Str::random(8)),
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemsData as $itemRow) {
                $quotation->items()->create($itemRow);
            }

            return $quotation;
        });

        return response()->json([
            'data' => $quotation->load(['customer:id,name,phone', 'items.product:id,name,sku', 'items.variant:id,name,sku']),
        ], 201);
    }

    public function show(Request $request, int $quotation): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $quotation)
                ->load(['customer:id,name,phone,email,address', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name']),
        ]);
    }

    public function update(Request $request, int $quotation): JsonResponse
    {
        $model = $this->findFor($request, $quotation);
        $data = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'quotation_date' => ['sometimes', 'required', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:pending,sent,accepted,declined,converted'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('customer:id,name,phone')]);
    }

    public function destroy(Request $request, int $quotation): JsonResponse
    {
        $model = $this->findFor($request, $quotation);
        $model->items()->delete();
        $model->delete();

        return response()->json(['message' => 'Quotation deleted successfully.']);
    }

    public function convertToSale(Request $request, int $quotation): JsonResponse
    {
        $model = $this->findFor($request, $quotation)->load('items');
        abort_if($model->status === 'converted', 422, 'Quotation has already been converted to sale.');

        $sale = DB::transaction(function () use ($model, $request) {
            $sale = Sale::create([
                'branch_id' => $model->branch_id,
                'customer_id' => $model->customer_id,
                'created_by' => $request->user()->id,
                'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
                'sold_at' => now(),
                'subtotal' => $model->subtotal,
                'discount' => $model->discount,
                'tax' => $model->tax,
                'total' => $model->total,
                'paid_amount' => 0,
                'due_amount' => $model->total,
                'payment_status' => 'due',
                'status' => 'completed',
            ]);

            foreach ($model->items as $item) {
                $sale->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'discount' => $item->discount,
                    'tax' => $item->tax,
                    'total' => $item->total,
                ]);
            }

            $model->update(['status' => 'converted']);

            return $sale;
        });

        return response()->json([
            'message' => 'Quotation converted to sale successfully.',
            'data' => $sale->load(['items.product:id,name,sku', 'customer:id,name']),
        ], 201);
    }

    private function queryFor(Request $request): Builder
    {
        return Quotation::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): Quotation
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
