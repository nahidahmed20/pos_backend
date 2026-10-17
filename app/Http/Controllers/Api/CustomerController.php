<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePos($request);

        if ($request->boolean('all') || $request->query('paginate') === 'false') {
            return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
        }

        return response()->json(['data' => $this->queryFor($request)->latest()->paginate(50)]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'opening_balance' => ['sometimes', 'numeric'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);

        $customer = Customer::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $customer->refresh()], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $customer): JsonResponse
    {
        $this->authorizePos($request);

        return response()->json(['data' => $this->queryFor($request)->findOrFail($customer)]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $customer): JsonResponse
    {
        $this->authorizePos($request);
        $model = $this->queryFor($request)->findOrFail($customer);
        $model->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'opening_balance' => ['sometimes', 'numeric'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]));

        return response()->json(['data' => $model->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $customer): JsonResponse
    {
        $this->authorizePos($request);
        $model = $this->queryFor($request)->findOrFail($customer);
        abort_if($model->sales()->exists(), 422, 'Customer has sales history.');
        $model->delete();

        return response()->json(['message' => 'Customer deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Customer::query()->when(! $request->user()->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id));
    }

    private function authorizePos(Request $request): void
    {
        abort_unless($request->user()?->can('manage pos'), 403);
    }
}
