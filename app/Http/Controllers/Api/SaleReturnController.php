<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItemBatch;
use App\Models\StockMovement;
use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleReturnController extends Controller
{
    public function __construct(private InventoryBatchService $inventoryBatches) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $returns = SaleReturn::query()
            ->when($branchId, fn (Builder $q) => $q->whereHas('sale', fn ($sq) => $sq->where('branch_id', $branchId)))
            ->with(['sale:id,invoice_number,customer_id', 'sale.customer:id,name,phone', 'items.saleItem.product:id,name,sku', 'items.saleItem.variant:id,name,sku'])
            ->latest()
            ->get();

        return response()->json(['data' => $returns]);
    }

    public function show(Request $request, int $saleReturn): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? null : $request->user()->branch_id;

        $return = SaleReturn::query()
            ->when($branchId, fn (Builder $q) => $q->whereHas('sale', fn ($sq) => $sq->where('branch_id', $branchId)))
            ->with(['sale.customer', 'sale.branch', 'items.saleItem.product', 'items.saleItem.variant', 'items.batchAllocations.inventoryBatch'])
            ->findOrFail($saleReturn);

        return response()->json(['data' => $return]);
    }

    public function store(Request $request, int $sale): JsonResponse
    {
        abort_unless($request->user()?->can('manage pos'), 403);
        $data = $request->validate([
            'return_number' => ['required', 'string', 'max:100'],
            'refund_amount' => ['sometimes', 'numeric', 'min:0'],
            'refund_method' => ['nullable', 'string', 'in:cash,card,mobile_banking'],
            'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $return = DB::transaction(function () use ($data, $sale, $request): SaleReturn {
            $saleModel = Sale::query()
                ->whereKey($sale)
                ->when(
                    ! $request->user()->isSuperAdmin(),
                    fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id),
                )
                ->lockForUpdate()
                ->firstOrFail();
            $items = [];
            foreach ($data['items'] as $item) {
                $saleItem = $saleModel->items()->with('product')->whereKey($item['sale_item_id'])->firstOrFail();
                $returnedQuantity = SaleReturn::query()
                    ->where('sale_id', $saleModel->id)
                    ->whereHas('items', fn (Builder $query) => $query->where('sale_item_id', $saleItem->id))
                    ->with('items')
                    ->get()
                    ->flatMap->items
                    ->where('sale_item_id', $saleItem->id)
                    ->sum('quantity');
                abort_if(
                    $returnedQuantity + $item['quantity'] > $saleItem->quantity,
                    422,
                    'Return quantity exceeds sold quantity.',
                );
                $items[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $item['quantity'],
                    'total' => $item['quantity'] * (float) $saleItem->unit_price,
                ];
            }
            $total = round((float) collect($items)->sum('total'), 2);
            $refundAmount = round((float) ($data['refund_amount'] ?? 0), 2);
            abort_if($refundAmount > $total, 422, 'Refund cannot exceed the returned item total.');
            abort_if(
                $refundAmount > 0 && empty($data['refund_method']),
                422,
                'A refund method is required when refunding money.',
            );
            $previousRefunds = (float) SaleReturn::query()
                ->where('sale_id', $saleModel->id)
                ->sum('refund_amount');
            abort_if(
                $refundAmount + $previousRefunds > (float) $saleModel->paid_amount,
                422,
                'Refund exceeds the amount collected for this sale.',
            );

            $cashSession = null;
            if (! empty($data['cash_register_session_id'])) {
                abort_unless(
                    $refundAmount > 0 && $data['refund_method'] === 'cash',
                    422,
                    'An open cash session can only be attached to a cash refund.',
                );
                $cashSession = CashRegisterSession::query()
                    ->whereKey($data['cash_register_session_id'])
                    ->where('status', 'open')
                    ->whereHas('cashRegister', fn (Builder $query) => $query->where('branch_id', $saleModel->branch_id))
                    ->when(
                        ! $request->user()->isSuperAdmin() && ! $request->user()->can('manage branches'),
                        fn (Builder $query) => $query->where('user_id', $request->user()->id),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $return = SaleReturn::create([
                'sale_id' => $saleModel->id,
                'branch_id' => $saleModel->branch_id,
                'created_by' => $request->user()->id,
                'return_number' => $data['return_number'],
                'total' => $total,
                'refund_amount' => $refundAmount,
                'refund_status' => $refundAmount > 0 ? 'refunded' : 'pending',
                'refund_method' => $data['refund_method'] ?? null,
                'refunded_at' => $refundAmount > 0 ? now() : null,
                'note' => $data['note'] ?? null,
            ]);
            foreach ($items as $item) {
                $returnItem = $return->items()->create([
                    'sale_item_id' => $item['sale_item']->id,
                    'product_id' => $item['sale_item']->product_id,
                    'variant_id' => $item['sale_item']->variant_id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['sale_item']->unit_price,
                    'total' => $item['total'],
                ]);
                $restoredLots = [];
                if ($item['sale_item']->product->has_expiry) {
                    $soldBatches = $item['sale_item']->batchAllocations()->with('inventoryBatch')->orderBy('id')->get();
                    if ($soldBatches->isEmpty()) {
                        $batch = $this->inventoryBatches->receive(
                            $saleModel->branch_id,
                            $item['sale_item']->product_id,
                            $item['sale_item']->variant_id,
                            $item['quantity'],
                            $item['sale_item']->batch_number ?? "RETURN-{$return->id}-{$item['sale_item']->id}",
                            null,
                            $item['sale_item']->expiry_date?->toDateString(),
                            (float) $item['sale_item']->cost_price,
                        );
                        $returnItem->batchAllocations()->create([
                            'inventory_batch_id' => $batch->id,
                            'quantity' => $item['quantity'],
                        ]);
                        $restoredLots[] = ['batch' => $batch, 'quantity' => $item['quantity']];
                    } else {
                        $remainingToRestore = $item['quantity'];
                        foreach ($soldBatches as $soldBatch) {
                            $alreadyReturned = SaleReturnItemBatch::query()
                                ->where('inventory_batch_id', $soldBatch->inventory_batch_id)
                                ->whereHas('saleReturnItem', fn (Builder $query) => $query->where('sale_item_id', $item['sale_item']->id))
                                ->sum('quantity');
                            $availableToRestore = $soldBatch->quantity - $alreadyReturned;
                            $quantityToRestore = min($remainingToRestore, $availableToRestore);
                            if ($quantityToRestore < 1) {
                                continue;
                            }
                            $this->inventoryBatches->restore($soldBatch->inventoryBatch, $quantityToRestore);
                            $returnItem->batchAllocations()->create([
                                'inventory_batch_id' => $soldBatch->inventory_batch_id,
                                'quantity' => $quantityToRestore,
                            ]);
                            $restoredLots[] = ['batch' => $soldBatch->inventoryBatch, 'quantity' => $quantityToRestore];
                            $remainingToRestore -= $quantityToRestore;
                            if ($remainingToRestore === 0) {
                                break;
                            }
                        }
                        abort_unless($remainingToRestore === 0, 422, 'Returned quantity exceeds the remaining batch allocation.');
                    }
                }
                $stock = BranchStock::query()->where([
                    'branch_id' => $saleModel->branch_id,
                    'product_id' => $item['sale_item']->product_id,
                    'variant_id' => $item['sale_item']->variant_id,
                ])->lockForUpdate()->firstOrFail();
                $balanceAfter = (int) $stock->quantity + $item['quantity'];
                $stock->update(['quantity' => $balanceAfter]);
                StockMovement::create([
                    'branch_id' => $saleModel->branch_id,
                    'product_id' => $item['sale_item']->product_id,
                    'variant_id' => $item['sale_item']->variant_id,
                    'user_id' => $request->user()->id,
                    'quantity' => $item['quantity'],
                    'balance_after' => $balanceAfter,
                    'type' => 'sale_return',
                    'batch_number' => count($restoredLots) === 1 ? $restoredLots[0]['batch']->batch_number : null,
                    'manufactured_date' => count($restoredLots) === 1 ? $restoredLots[0]['batch']->manufactured_date : null,
                    'expiry_date' => count($restoredLots) === 1 ? $restoredLots[0]['batch']->expiry_date : null,
                    'reference_type' => SaleReturn::class,
                    'reference_id' => $return->id,
                ]);
            }
            if ($cashSession !== null) {
                CashMovement::create([
                    'cash_register_session_id' => $cashSession->id,
                    'user_id' => $request->user()->id,
                    'direction' => 'out',
                    'category' => 'sale_refund',
                    'amount' => $refundAmount,
                    'description' => "Refund for sale return {$return->return_number}",
                    'reference_type' => SaleReturn::class,
                    'reference_id' => $return->id,
                ]);
            }

            return $return;
        });

        return response()->json(['data' => $return->load('items.batchAllocations.inventoryBatch')], 201);
    }
}
