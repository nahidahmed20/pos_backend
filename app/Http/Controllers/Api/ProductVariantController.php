<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function allVariants(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $variants = ProductVariant::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['product:id,name,sku,image_path', 'stock'])
            ->latest()
            ->get();

        return response()->json(['data' => $variants]);
    }

    public function index(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);
        $parent = $this->productFor($request, $product);

        return response()->json(['data' => $parent->variants()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);
        $parent = $this->productFor($request, $product);
        $data = $this->validated($request);
        abort_unless(! $parent->variants()->where('sku', $data['sku'])->exists(), 422, 'The variant SKU has already been taken.');

        $variant = $parent->variants()->create([...$data, 'branch_id' => $parent->branch_id]);

        return response()->json(['data' => $variant], 201);
    }

    public function show(Request $request, int $product, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->variantFor($request, $product, $variant)]);
    }

    public function update(Request $request, int $product, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->variantFor($request, $product, $variant);
        $data = $this->validated($request, true);
        if (isset($data['sku'])) {
            abort_unless(! ProductVariant::where('product_id', $model->product_id)->where('sku', $data['sku'])->where('id', '!=', $model->id)->exists(), 422, 'The variant SKU has already been taken.');
        }
        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $product, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->variantFor($request, $product, $variant);
        $model->delete();

        return response()->json(['message' => 'Product variant deleted successfully.']);
    }

    public function showDirect(Request $request, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = ProductVariant::query()
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->with(['product:id,name,sku,image_path', 'stock'])
            ->findOrFail($variant);

        return response()->json(['data' => $model]);
    }

    public function updateDirect(Request $request, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = ProductVariant::query()
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->findOrFail($variant);
        $data = $this->validated($request, true);
        if (isset($data['sku'])) {
            abort_unless(! ProductVariant::where('product_id', $model->product_id)->where('sku', $data['sku'])->where('id', '!=', $model->id)->exists(), 422, 'The variant SKU has already been taken.');
        }
        $model->update($data);

        return response()->json(['data' => $model->fresh()->load(['product:id,name,sku,image_path', 'stock'])]);
    }

    public function destroyDirect(Request $request, int $variant): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = ProductVariant::query()
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->findOrFail($variant);
        $model->delete();

        return response()->json(['message' => 'Product variant deleted successfully.']);
    }

    private function productFor(Request $request, int $id): Product
    {
        return Product::query()
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->findOrFail($id);
    }

    private function variantFor(Request $request, int $product, int $variant): ProductVariant
    {
        return $this->productFor($request, $product)->variants()->findOrFail($variant);
    }

    private function validated(Request $request, bool $update = false): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'attributes' => ['sometimes', 'nullable', 'array'],
            'sku' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => [$update ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'min_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
