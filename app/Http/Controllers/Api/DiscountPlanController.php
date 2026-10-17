<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->with('discounts')->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $plan = DiscountPlan::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $plan->refresh()->load('discounts')], 201);
    }

    public function show(Request $request, int $discountPlan): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $discountPlan)->load('discounts')]);
    }

    public function update(Request $request, int $discountPlan): JsonResponse
    {
        $model = $this->findFor($request, $discountPlan);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('discounts')]);
    }

    public function destroy(Request $request, int $discountPlan): JsonResponse
    {
        $model = $this->findFor($request, $discountPlan);
        $model->delete();

        return response()->json(['message' => 'Discount plan deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return DiscountPlan::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): DiscountPlan
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
