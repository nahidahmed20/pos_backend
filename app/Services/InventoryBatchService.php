<?php

namespace App\Services;

use App\Models\InventoryBatch;
use Illuminate\Database\Eloquent\Builder;

class InventoryBatchService
{
    /**
     * @return array<int, array{batch: InventoryBatch, quantity: int}>
     */
    public function takeForSale(int $branchId, int $productId, ?int $variantId, int $quantity): array
    {
        return $this->take($branchId, $productId, $variantId, $quantity, true);
    }

    /**
     * @return array<int, array{batch: InventoryBatch, quantity: int}>
     */
    public function takeForRemoval(int $branchId, int $productId, ?int $variantId, int $quantity): array
    {
        return $this->take($branchId, $productId, $variantId, $quantity, false);
    }

    /**
     * @return array<int, array{batch: InventoryBatch, quantity: int}>
     */
    public function takeForAdjustment(int $branchId, int $productId, ?int $variantId, int $quantity): array
    {
        return $this->take($branchId, $productId, $variantId, $quantity, false, true);
    }

    /**
     * @return array<int, array{batch: InventoryBatch, quantity: int}>
     */
    public function takeForTransfer(int $branchId, int $productId, ?int $variantId, int $quantity): array
    {
        return $this->take($branchId, $productId, $variantId, $quantity, false);
    }

    public function receive(
        int $branchId,
        int $productId,
        ?int $variantId,
        int $quantity,
        ?string $batchNumber,
        ?string $manufacturedDate,
        ?string $expiryDate,
        float $unitCost,
    ): InventoryBatch {
        return InventoryBatch::create([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'batch_number' => $batchNumber,
            'manufactured_date' => $manufacturedDate,
            'expiry_date' => $expiryDate,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ]);
    }

    public function restore(InventoryBatch $batch, int $quantity): void
    {
        $batch->increment('quantity', $quantity);
    }

    /**
     * @return array<int, array{batch: InventoryBatch, quantity: int}>
     */
    private function take(
        int $branchId,
        int $productId,
        ?int $variantId,
        int $quantity,
        bool $sellableOnly,
        bool $allowUnregistered = false,
    ): array {
        $batches = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('quantity', '>', 0)
            ->when(
                $sellableOnly,
                fn (Builder $query) => $query
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', today()),
            )
            ->orderBy('expiry_date')
            ->orderBy('manufactured_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $availableQuantity = $batches->sum('quantity');
        abort_unless($allowUnregistered || $availableQuantity >= $quantity, 422, $sellableOnly
            ? 'Insufficient unexpired batch stock.'
            : 'Insufficient batch stock.');

        $remaining = min($quantity, $availableQuantity);
        $allocations = [];
        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }
            $allocatedQuantity = min($remaining, $batch->quantity);
            $batch->decrement('quantity', $allocatedQuantity);
            $allocations[] = ['batch' => $batch, 'quantity' => $allocatedQuantity];
            $remaining -= $allocatedQuantity;
        }

        return $allocations;
    }
}
