<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function __construct(private InventoryBatchService $inventoryBatches) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json([
            'data' => $this->queryFor($request)->with(['product:id,name,sku', 'branch:id,name', 'user:id,name'])->latest()->paginate(50),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'manufactured_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:manufactured_date'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);

        $movement = DB::transaction(function () use ($data, $branchId, $request): StockMovement {
            $product = Product::query()
                ->whereKey($data['product_id'])
                ->firstOrFail();
            $quantity = (int) $data['quantity'];
            $variant = null;
            if (! empty($data['variant_id'])) {
                $variant = ProductVariant::query()
                    ->whereKey($data['variant_id'])
                    ->where('product_id', $product->id)
                    ->firstOrFail();
            }
            $stock = BranchStock::query()
                ->where('branch_id', $branchId)
                ->where('product_id', $data['product_id'])
                ->where('variant_id', $variant?->id)
                ->lockForUpdate()
                ->first();
            if ($stock === null) {
                abort_unless($product->branch_id === $branchId, 404);
                $stock = BranchStock::create([
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => 0,
                    'reorder_level' => 0,
                ]);
            }
            $newBalance = (int) $stock->quantity + $quantity;
            abort_if($newBalance < 0, 422, 'Stock cannot become negative.');
            $batchAllocations = [];
            $receivedBatch = null;
            if ($product->has_expiry) {
                if ($quantity > 0) {
                    abort_unless(
                        ! empty($data['expiry_date']),
                        422,
                        'An expiry date is required for expiry-tracked stock adjustments.',
                    );
                    $receivedBatch = $this->inventoryBatches->receive(
                        $branchId,
                        $product->id,
                        $variant?->id,
                        $quantity,
                        $data['batch_number'] ?? null,
                        $data['manufactured_date'] ?? null,
                        $data['expiry_date'],
                        (float) ($variant?->purchase_price ?? $product->purchase_price),
                    );
                } else {
                    $batchAllocations = $this->inventoryBatches->takeForAdjustment(
                        $branchId,
                        $product->id,
                        $variant?->id,
                        abs($quantity),
                    );
                }
            }
            $stock->update(['quantity' => $newBalance]);
            $singleBatch = $receivedBatch ?? (count($batchAllocations) === 1 ? $batchAllocations[0]['batch'] : null);

            return StockMovement::create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'user_id' => $request->user()->id,
                'quantity' => $quantity,
                'balance_after' => $newBalance,
                'type' => 'adjustment',
                'note' => $data['note'] ?? null,
                'batch_number' => $singleBatch?->batch_number,
                'manufactured_date' => $singleBatch?->manufactured_date,
                'expiry_date' => $singleBatch?->expiry_date,
            ]);
        });

        return response()->json(['data' => $movement->load(['product:id,name,sku', 'branch:id,name'])], 201);
    }

    private function queryFor(Request $request): Builder
    {
        return StockMovement::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
