<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePurchases($request);

        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePurchases($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'opening_balance' => ['sometimes', 'numeric'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);
        $supplier = Supplier::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $supplier->refresh()], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $supplier): JsonResponse
    {
        $this->authorizePurchases($request);

        return response()->json(['data' => $this->findFor($request, $supplier)]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $supplier): JsonResponse
    {
        $this->authorizePurchases($request);
        $model = $this->findFor($request, $supplier);
        $model->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'opening_balance' => ['sometimes', 'numeric'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $model->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $supplier): JsonResponse
    {
        $this->authorizePurchases($request);
        $model = $this->findFor($request, $supplier);
        abort_if($model->purchases()->exists(), 422, 'Supplier has purchase history.');
        $model->delete();

        return response()->json(['message' => 'Supplier deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Supplier::query()->when(! $request->user()->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id));
    }

    private function findFor(Request $request, int $id): Supplier
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function authorizePurchases(Request $request): void
    {
        abort_unless($request->user()?->can('manage purchases'), 403);
    }
}
