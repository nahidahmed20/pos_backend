<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)
                ->with(['items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'items.*.type' => ['required', 'string', 'in:addition,subtraction'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);

        $adjustment = DB::transaction(function () use ($data, $branchId, $request) {
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                $totalAmount += ($item['quantity'] * ($item['unit_cost'] ?? 0));
            }

            $adjustment = StockAdjustment::create([
                'branch_id' => $branchId,
                'created_by' => $request->user()->id,
                'reference_number' => 'ADJ-'.strtoupper(Str::random(8)),
                'adjustment_date' => $data['adjustment_date'],
                'total_amount' => $totalAmount,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
            ]);

            foreach ($data['items'] as $item) {
                $adjustment->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'] ?? 0,
                    'type' => $item['type'],
                ]);

                // Update Branch Stock
                $stock = BranchStock::firstOrCreate([
                    'branch_id' => $branchId,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                ], [
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'damaged_quantity' => 0,
                    'reorder_level' => 10,
                ]);

                $changeQty = ($item['type'] === 'addition') ? $item['quantity'] : -$item['quantity'];
                $stock->quantity += $changeQty;
                $stock->save();

                // Record movement
                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'quantity' => $changeQty,
                    'balance_after' => $stock->quantity,
                    'unit_cost' => $item['unit_cost'] ?? 0,
                    'type' => 'adjustment',
                    'reason' => $data['reason'],
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $adjustment->id,
                ]);
            }

            return $adjustment;
        });

        return response()->json([
            'data' => $adjustment->load(['items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name']),
        ], 201);
    }

    public function show(Request $request, int $stockAdjustment): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $stockAdjustment)
                ->load(['items.product:id,name,sku', 'items.variant:id,name,sku', 'creator:id,name']),
        ]);
    }

    public function destroy(Request $request, int $stockAdjustment): JsonResponse
    {
        $adjustment = $this->findFor($request, $stockAdjustment);
        $adjustment->items()->delete();
        $adjustment->delete();

        return response()->json(['message' => 'Stock adjustment deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return StockAdjustment::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): StockAdjustment
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
