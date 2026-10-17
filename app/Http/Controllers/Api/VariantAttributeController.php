<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VariantAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariantAttributeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->with('values')->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'values' => ['sometimes', 'array'],
            'values.*.value' => ['required_with:values', 'string', 'max:255'],
            'values.*.color_code' => ['nullable', 'string', 'max:50'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $attribute = VariantAttribute::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'branch_id' => $branchId,
        ]);

        if (! empty($data['values'])) {
            foreach ($data['values'] as $val) {
                $attribute->values()->create($val);
            }
        }

        return response()->json(['data' => $attribute->load('values')], 201);
    }

    public function show(Request $request, int $variantAttribute): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $variantAttribute)->load('values')]);
    }

    public function update(Request $request, int $variantAttribute): JsonResponse
    {
        $model = $this->findFor($request, $variantAttribute);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'values' => ['sometimes', 'array'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.value' => ['required_with:values', 'string', 'max:255'],
            'values.*.color_code' => ['nullable', 'string', 'max:50'],
        ]);

        $model->update([
            'name' => $data['name'] ?? $model->name,
            'code' => array_key_exists('code', $data) ? $data['code'] : $model->code,
            'is_active' => $data['is_active'] ?? $model->is_active,
        ]);

        if (isset($data['values'])) {
            $model->values()->delete();
            foreach ($data['values'] as $val) {
                $model->values()->create([
                    'value' => $val['value'],
                    'color_code' => $val['color_code'] ?? null,
                ]);
            }
        }

        return response()->json(['data' => $model->fresh()->load('values')]);
    }

    public function destroy(Request $request, int $variantAttribute): JsonResponse
    {
        $model = $this->findFor($request, $variantAttribute);
        $model->delete();

        return response()->json(['message' => 'Variant attribute deleted successfully.']);
    }

    public function storeValue(Request $request, int $variantAttribute): JsonResponse
    {
        $attribute = $this->findFor($request, $variantAttribute);
        $data = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'color_code' => ['nullable', 'string', 'max:50'],
        ]);

        $val = $attribute->values()->create($data);

        return response()->json(['data' => $val], 201);
    }

    public function destroyValue(Request $request, int $variantAttribute, int $value): JsonResponse
    {
        $attribute = $this->findFor($request, $variantAttribute);
        $val = $attribute->values()->findOrFail($value);
        $val->delete();

        return response()->json(['message' => 'Attribute value deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return VariantAttribute::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): VariantAttribute
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
