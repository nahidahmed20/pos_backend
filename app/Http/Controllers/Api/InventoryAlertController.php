<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\InventoryBatch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    /**
     * Get expired or expiring soon inventory batches.
     */
    public function expiredProducts(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;
        $days = (int) $request->query('days', 0); // 0 means already expired, > 0 means expiring within X days

        $query = InventoryBatch::query()
            ->with(['product:id,name,sku,barcode,selling_price', 'variant:id,product_id,name,sku'])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date');

        if ($days > 0) {
            $query->where('expiry_date', '<=', now()->addDays($days)->toDateString());
        } else {
            $query->where('expiry_date', '<=', now()->toDateString());
        }

        return response()->json([
            'data' => $query->orderBy('expiry_date', 'asc')->get(),
        ]);
    }

    /**
     * Get products with low stock (quantity <= reorder_level).
     */
    public function lowStocks(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $query = BranchStock::query()
            ->with(['product:id,name,sku,barcode,selling_price,unit', 'variant:id,name,sku', 'branch:id,name'])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereColumn('quantity', '<=', 'reorder_level');

        return response()->json([
            'data' => $query->latest()->get(),
        ]);
    }

    /**
     * Get products & variants formatted for barcode printing.
     */
    public function barcodePrint(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $products = Product::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->with('variants:id,product_id,name,sku,barcode,selling_price')
            ->select('id', 'branch_id', 'name', 'sku', 'barcode', 'selling_price', 'brand')
            ->get();

        return response()->json(['data' => $products]);
    }

    /**
     * Get products & variants formatted for QR code printing.
     */
    public function qrcodePrint(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $products = Product::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->with('variants:id,product_id,name,sku,selling_price')
            ->select('id', 'branch_id', 'name', 'sku', 'selling_price', 'description')
            ->get();

        return response()->json(['data' => $products]);
    }

    /**
     * Get sub categories (categories where parent_id is not null).
     */
    public function subCategories(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $query = Category::query()
            ->whereNotNull('parent_id')
            ->with('parent:id,name')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));

        return response()->json(['data' => $query->latest()->get()]);
    }
}
