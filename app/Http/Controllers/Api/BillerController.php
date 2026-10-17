<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Biller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'alt_phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $biller = Biller::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $biller->refresh()], 201);
    }

    public function show(Request $request, int $biller): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $biller)]);
    }

    public function update(Request $request, int $biller): JsonResponse
    {
        $model = $this->findFor($request, $biller);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'alt_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $biller): JsonResponse
    {
        $model = $this->findFor($request, $biller);
        $model->delete();

        return response()->json(['message' => 'Biller deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Biller::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Biller
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
