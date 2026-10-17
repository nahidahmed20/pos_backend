<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function __construct(private InventoryBatchService $inventoryBatches) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->queryFor($request)->with(['fromBranch:id,name', 'toBranch:id,name', 'items.product:id,name,sku', 'items.variant'])->latest()->paginate(25)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless($request->user()->isSuperAdmin() || $data['from_branch_id'] === $request->user()->branch_id, 403);

        $transfer = DB::transaction(function () use ($data, $request): StockTransfer {
            $transfer = StockTransfer::create([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'created_by' => $request->user()->id,
                'status' => 'completed',
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::query()->whereKey($item['product_id'])->firstOrFail();
                $variantId = $item['variant_id'] ?? null;
                if ($variantId !== null) {
                    ProductVariant::query()
                        ->whereKey($variantId)
                        ->where('product_id', $product->id)
                        ->firstOrFail();
                }
                $source = BranchStock::where('branch_id', $data['from_branch_id'])
                    ->where('product_id', $item['product_id'])
                    ->where('variant_id', $variantId)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_if($source->quantity < $item['quantity'], 422, 'Insufficient stock for transfer.');
                $batchAllocations = $product->has_expiry
                    ? $this->inventoryBatches->takeForTransfer(
                        $data['from_branch_id'],
                        $product->id,
                        $variantId,
                        $item['quantity'],
                    )
                    : [];
                $destination = BranchStock::firstOrCreate(
                    ['branch_id' => $data['to_branch_id'], 'product_id' => $item['product_id'], 'variant_id' => $variantId],
                    ['quantity' => 0, 'reorder_level' => 0],
                );
                $destination = BranchStock::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
                $sourceBalance = $source->quantity - $item['quantity'];
                $destinationBalance = $destination->quantity + $item['quantity'];
                $source->decrement('quantity', $item['quantity']);
                $destination->increment('quantity', $item['quantity']);
                $transfer->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => $item['quantity'],
                ]);
                $lots = $batchAllocations ?: [['batch' => null, 'quantity' => $item['quantity']]];
                $sourceMovementBalance = $sourceBalance + $item['quantity'];
                $destinationMovementBalance = $destinationBalance - $item['quantity'];
                foreach ($lots as $allocation) {
                    $sourceMovementBalance -= $allocation['quantity'];
                    $destinationMovementBalance += $allocation['quantity'];
                    $batch = $allocation['batch'];
                    if ($batch !== null) {
                        $batch = $this->inventoryBatches->receive(
                            $data['to_branch_id'],
                            $product->id,
                            $variantId,
                            $allocation['quantity'],
                            $batch->batch_number,
                            $batch->manufactured_date?->toDateString(),
                            $batch->expiry_date?->toDateString(),
                            (float) $batch->unit_cost,
                        );
                    }
                    StockMovement::create([
                        'branch_id' => $data['from_branch_id'], 'product_id' => $product->id, 'variant_id' => $variantId,
                        'user_id' => $request->user()->id, 'quantity' => -$allocation['quantity'], 'type' => 'transfer_out',
                        'balance_after' => $sourceMovementBalance,
                        'batch_number' => $batch?->batch_number,
                        'manufactured_date' => $batch?->manufactured_date,
                        'expiry_date' => $batch?->expiry_date,
                        'reference_type' => StockTransfer::class, 'reference_id' => $transfer->id,
                    ]);
                    StockMovement::create([
                        'branch_id' => $data['to_branch_id'], 'product_id' => $product->id, 'variant_id' => $variantId,
                        'user_id' => $request->user()->id, 'quantity' => $allocation['quantity'], 'type' => 'transfer_in',
                        'balance_after' => $destinationMovementBalance,
                        'batch_number' => $batch?->batch_number,
                        'manufactured_date' => $batch?->manufactured_date,
                        'expiry_date' => $batch?->expiry_date,
                        'reference_type' => StockTransfer::class, 'reference_id' => $transfer->id,
                    ]);
                }
            }

            return $transfer;
        });

        return response()->json(['data' => $transfer->load(['fromBranch:id,name', 'toBranch:id,name', 'items.product:id,name,sku', 'items.variant'])], 201);
    }

    private function queryFor(Request $request): Builder
    {
        return StockTransfer::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $query) use ($request): void {
                $query->where('from_branch_id', $request->user()->branch_id)->orWhere('to_branch_id', $request->user()->branch_id);
            });
        });
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
