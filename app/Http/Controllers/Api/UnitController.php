<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'allow_decimal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $unit = Unit::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $unit->refresh()], 201);
    }

    public function show(Request $request, int $unit): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $unit)]);
    }

    public function update(Request $request, int $unit): JsonResponse
    {
        $model = $this->findFor($request, $unit);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'allow_decimal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $unit): JsonResponse
    {
        $model = $this->findFor($request, $unit);
        $model->delete();

        return response()->json(['message' => 'Unit deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Unit::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Unit
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
