<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_type' => ['sometimes', 'string', 'in:days,months,years'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $warranty = Warranty::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $warranty->refresh()], 201);
    }

    public function show(Request $request, int $warranty): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $warranty)]);
    }

    public function update(Request $request, int $warranty): JsonResponse
    {
        $model = $this->findFor($request, $warranty);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'duration' => ['sometimes', 'required', 'integer', 'min:1'],
            'duration_type' => ['sometimes', 'string', 'in:days,months,years'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $warranty): JsonResponse
    {
        $model = $this->findFor($request, $warranty);
        $model->delete();

        return response()->json(['message' => 'Warranty deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Warranty::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Warranty
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
