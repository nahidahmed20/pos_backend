<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->queryFor($request)->with(['category:id,name', 'stock', 'variants.stock'])->latest()->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $this->validated($request);
        $variantsData = $data['variants'] ?? [];
        unset($data['variants']);
        $data = $this->resolveRelations($data);
        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        abort_unless(Category::whereKey($data['category_id'])->where('branch_id', $branchId)->exists(), 422, 'Category does not belong to this branch.');
        abort_unless(! Product::where('branch_id', $branchId)->where('sku', $data['sku'])->exists(), 422, 'The SKU has already been taken in this branch.');

        if (! empty($variantsData)) {
            $duplicateSkus = collect($variantsData)->pluck('sku')->duplicates();
            abort_if($duplicateSkus->isNotEmpty(), 422, 'Duplicate SKU found in variants.');
            $existingVariantSkus = ProductVariant::where('branch_id', $branchId)
                ->whereIn('sku', collect($variantsData)->pluck('sku'))
                ->pluck('sku');
            abort_if($existingVariantSkus->isNotEmpty(), 422, 'The variant SKU has already been taken in this branch.');
        }

        $product = DB::transaction(function () use ($data, $branchId, $variantsData) {
            $product = Product::create([...$data, 'branch_id' => $branchId]);

            foreach ($variantsData as $variantItem) {
                $product->variants()->create([
                    ...$variantItem,
                    'branch_id' => $branchId,
                ]);
            }

            return $product;
        });

        return response()->json(['data' => $product->load(['category:id,name', 'stock', 'variants'])], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->findFor($request, $product)->load(['category:id,name', 'stock', 'variants'])]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->findFor($request, $product);
        $data = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sku' => ['sometimes', 'required', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secondary_barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'product_type' => ['sometimes', 'required', 'string', 'max:50'],
            'model_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'length' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'warranty_id' => ['sometimes', 'nullable', 'integer', 'exists:warranties,id'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'min_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'has_expiry' => ['sometimes', 'boolean'],
            'is_serialized' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['sometimes', 'string', 'max:255'],
            'variants.*.sku' => ['sometimes', 'string', 'max:255'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.image_path' => ['nullable', 'string', 'max:255'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ]);
        $variantsData = $data['variants'] ?? null;
        unset($data['variants']);
        $data = $this->resolveRelations($data);
        if (isset($data['category_id'])) {
            abort_unless(Category::whereKey($data['category_id'])->where('branch_id', $model->branch_id)->exists(), 422, 'Category does not belong to this branch.');
        }
        if (isset($data['sku'])) {
            abort_unless(! Product::where('branch_id', $model->branch_id)->where('sku', $data['sku'])->where('id', '!=', $model->id)->exists(), 422, 'The SKU has already been taken in this branch.');
        }
        $model->update($data);

        if ($variantsData !== null && is_array($variantsData)) {
            foreach ($variantsData as $variantItem) {
                if (! empty($variantItem['id'])) {
                    $variant = $model->variants()->find($variantItem['id']);
                    if ($variant) {
                        $variant->update($variantItem);
                    }
                } else {
                    $model->variants()->create([
                        ...$variantItem,
                        'branch_id' => $model->branch_id,
                    ]);
                }
            }
        }

        return response()->json(['data' => $model->fresh()->load(['category:id,name', 'stock', 'variants'])]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $product): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->findFor($request, $product);
        $model->variants()->delete();
        $model->delete();

        return response()->json(['message' => 'Product deleted successfully.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'secondary_barcode' => ['nullable', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'product_type' => ['sometimes', 'string', 'max:50'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'warranty_id' => ['nullable', 'integer', 'exists:warranties,id'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'min_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'has_expiry' => ['sometimes', 'boolean'],
            'is_serialized' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.image_path' => ['nullable', 'string', 'max:255'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function resolveRelations(array $data): array
    {
        if (! empty($data['brand_id'])) {
            $brand = Brand::find($data['brand_id']);
            if ($brand) {
                $data['brand'] = $brand->name;
            }
            unset($data['brand_id']);
        }

        if (! empty($data['unit_id'])) {
            $unit = Unit::find($data['unit_id']);
            if ($unit) {
                $data['unit'] = $unit->short_name ?: $unit->name;
            }
            unset($data['unit_id']);
        }

        if (! empty($data['warranty_id'])) {
            $warranty = Warranty::find($data['warranty_id']);
            if ($warranty) {
                $data['warranty_months'] = $warranty->duration;
            }
            unset($data['warranty_id']);
        }

        return $data;
    }

    private function queryFor(Request $request): Builder
    {
        return Product::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): Product
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): int
    {
        $branchId = $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);

        return $branchId;
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }
}
