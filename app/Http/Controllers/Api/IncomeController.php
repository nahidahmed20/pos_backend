<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Income;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)->with(['category:id,name', 'creator:id,name'])->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'income_category_id' => ['nullable', 'integer', 'exists:income_categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'income_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $income = Income::create([
            ...$data,
            'branch_id' => $branchId,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $income->refresh()->load(['category:id,name', 'creator:id,name'])], 201);
    }

    public function show(Request $request, int $income): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $income)->load(['category:id,name', 'creator:id,name']),
        ]);
    }

    public function update(Request $request, int $income): JsonResponse
    {
        $model = $this->findFor($request, $income);
        $data = $request->validate([
            'income_category_id' => ['sometimes', 'nullable', 'integer', 'exists:income_categories,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'string', 'max:50'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'income_date' => ['sometimes', 'required', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load(['category:id,name', 'creator:id,name'])]);
    }

    public function destroy(Request $request, int $income): JsonResponse
    {
        $model = $this->findFor($request, $income);
        $model->delete();

        return response()->json(['message' => 'Income record deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Income::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): Income
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): int
    {
        $branchId = $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'Branch ID is required.');

        return $branchId;
    }
}
