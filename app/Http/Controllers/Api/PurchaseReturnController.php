<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)
                ->with(['supplier:id,name,phone', 'purchase:id,invoice_number', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'returned_at' => ['required', 'date'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);

        $return = DB::transaction(function () use ($data, $branchId, $request) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_cost'];
                $subtotal += $itemSubtotal;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => $itemSubtotal,
                    'total' => $itemSubtotal,
                ];
            }

            $tax = $data['tax'] ?? 0;
            $total = $subtotal + $tax;

            $purchaseReturn = PurchaseReturn::create([
                'branch_id' => $branchId,
                'purchase_id' => $data['purchase_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $request->user()->id,
                'return_number' => 'PR-'.strtoupper(Str::random(8)),
                'returned_at' => $data['returned_at'],
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'status' => 'completed',
                'refund_status' => 'pending',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemsData as $row) {
                $purchaseReturn->items()->create($row);

                // Deduct stock
                $stock = BranchStock::firstOrCreate([
                    'branch_id' => $branchId,
                    'product_id' => $row['product_id'],
                    'variant_id' => $row['variant_id'],
                ], [
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'damaged_quantity' => 0,
                    'reorder_level' => 10,
                ]);

                $stock->quantity -= $row['quantity'];
                $stock->save();

                // Movement
                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $row['product_id'],
                    'variant_id' => $row['variant_id'],
                    'user_id' => $request->user()->id,
                    'quantity' => -$row['quantity'],
                    'balance_after' => $stock->quantity,
                    'unit_cost' => $row['unit_cost'],
                    'type' => 'purchase_return',
                    'reason' => $data['reason'] ?? 'Return to supplier',
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $purchaseReturn->id,
                ]);
            }

            return $purchaseReturn;
        });

        return response()->json([
            'data' => $return->load(['supplier:id,name,phone', 'items.product:id,name,sku', 'items.variant:id,name,sku']),
        ], 201);
    }

    public function show(Request $request, int $purchaseReturn): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $purchaseReturn)
                ->load(['supplier:id,name,phone,email', 'purchase:id,invoice_number', 'items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name']),
        ]);
    }

    public function update(Request $request, int $purchaseReturn): JsonResponse
    {
        $model = $this->findFor($request, $purchaseReturn);
        $data = $request->validate([
            'refund_status' => ['sometimes', 'string', 'in:pending,refunded'],
            'status' => ['sometimes', 'string', 'in:pending,completed'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('supplier:id,name,phone')]);
    }

    public function destroy(Request $request, int $purchaseReturn): JsonResponse
    {
        $model = $this->findFor($request, $purchaseReturn);
        $model->items()->delete();
        $model->delete();

        return response()->json(['message' => 'Purchase return deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return PurchaseReturn::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): PurchaseReturn
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
