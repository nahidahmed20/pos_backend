<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)->with('plan:id,name')->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discount_plan_id' => ['nullable', 'integer', 'exists:discount_plans,id'],
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', 'string', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $discount = Discount::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $discount->refresh()->load('plan:id,name')], 201);
    }

    public function show(Request $request, int $discount): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $discount)->load('plan:id,name')]);
    }

    public function update(Request $request, int $discount): JsonResponse
    {
        $model = $this->findFor($request, $discount);
        $data = $request->validate([
            'discount_plan_id' => ['sometimes', 'nullable', 'integer', 'exists:discount_plans,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'discount_type' => ['sometimes', 'required', 'string', 'in:percentage,fixed'],
            'value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('plan:id,name')]);
    }

    public function destroy(Request $request, int $discount): JsonResponse
    {
        $model = $this->findFor($request, $discount);
        $model->delete();

        return response()->json(['message' => 'Discount deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Discount::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Discount
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
