<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->queryFor($request)->with(['product:id,name,sku', 'branch:id,name'])->latest()->get()]);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);
        $productModel = Product::whereKey($product)->where('branch_id', $branchId)->firstOrFail();
        $stock = DB::transaction(fn (): BranchStock => BranchStock::updateOrCreate(
            ['branch_id' => $branchId, 'product_id' => $productModel->id],
            ['quantity' => $data['quantity'], 'reorder_level' => $data['reorder_level'] ?? 0],
        ));

        return response()->json(['data' => $stock->load(['product:id,name,sku', 'branch:id,name'])]);
    }

    private function queryFor(Request $request): Builder
    {
        return BranchStock::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
