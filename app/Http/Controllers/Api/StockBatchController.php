<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variants,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin()
            ? ($filters['branch_id'] ?? null)
            : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'A branch is required to view inventory batches.');

        $batches = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $productId) => $query->where('product_id', $productId))
            ->when(array_key_exists('variant_id', $filters), fn (Builder $query) => $query->where('variant_id', $filters['variant_id']))
            ->with(['product:id,name,sku', 'variant:id,name,sku'])
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate(50);

        return response()->json(['data' => $batches]);
    }

    public function registerOpening(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'manufactured_date' => ['nullable', 'date'],
            'expiry_date' => ['required', 'date', 'after_or_equal:manufactured_date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $branchId = $request->user()->isSuperAdmin()
            ? ($data['branch_id'] ?? null)
            : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'A branch is required to register opening batch stock.');

        $batch = DB::transaction(function () use ($data, $branchId, $request): InventoryBatch {
            $product = Product::query()->whereKey($data['product_id'])->firstOrFail();
            abort_unless($product->has_expiry, 422, 'Only expiry-tracked products require opening batch registration.');
            $variant = null;
            if (! empty($data['variant_id'])) {
                $variant = ProductVariant::query()
                    ->whereKey($data['variant_id'])
                    ->where('product_id', $product->id)
                    ->firstOrFail();
            }
            $stock = BranchStock::query()
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variant?->id)
                ->lockForUpdate()
                ->firstOrFail();
            $registeredQuantity = InventoryBatch::query()
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variant?->id)
                ->sum('quantity');
            $unregisteredQuantity = $stock->quantity - $registeredQuantity;
            abort_unless(
                $data['quantity'] <= $unregisteredQuantity,
                422,
                "Only {$unregisteredQuantity} unregistered stock units are available.",
            );

            $batch = InventoryBatch::create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'batch_number' => $data['batch_number'] ?? null,
                'manufactured_date' => $data['manufactured_date'] ?? null,
                'expiry_date' => $data['expiry_date'],
                'quantity' => $data['quantity'],
                'unit_cost' => $variant?->purchase_price ?? $product->purchase_price,
            ]);
            StockMovement::create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'user_id' => $request->user()->id,
                'quantity' => 0,
                'balance_after' => $stock->quantity,
                'type' => 'batch_registration',
                'note' => $data['note'] ?? 'Opening stock batch registration',
                'batch_number' => $batch->batch_number,
                'manufactured_date' => $batch->manufactured_date,
                'expiry_date' => $batch->expiry_date,
            ]);

            return $batch;
        });

        return response()->json(['data' => $batch->load(['product:id,name,sku', 'variant:id,name,sku'])], 201);
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
