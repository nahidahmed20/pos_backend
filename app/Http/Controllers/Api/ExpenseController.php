<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeExpenses($request);

        return response()->json(['data' => $this->queryFor($request)->latest()->paginate(50)]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeExpenses($request);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['sometimes', 'required', 'string', 'in:cash,card,mobile_banking,bank_transfer,cheque'],
            'reference' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
            'receipt_path' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);

        return response()->json(['data' => Expense::create([...$data, 'branch_id' => $branchId, 'created_by' => $request->user()->id])], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $expense): JsonResponse
    {
        $this->authorizeExpenses($request);

        return response()->json(['data' => $this->queryFor($request)->findOrFail($expense)]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $expense): JsonResponse
    {
        $this->authorizeExpenses($request);
        $model = $this->queryFor($request)->findOrFail($expense);
        $model->update($request->validate([
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'payment_method' => ['sometimes', 'required', 'string', 'in:cash,card,mobile_banking,bank_transfer,cheque'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'spent_at' => ['sometimes', 'date'],
            'receipt_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]));

        return response()->json(['data' => $model->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $expense): JsonResponse
    {
        $this->authorizeExpenses($request);
        $this->queryFor($request)->findOrFail($expense)->delete();

        return response()->json(['message' => 'Expense deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Expense::query()->when(! $request->user()->isSuperAdmin(), fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id));
    }

    private function authorizeExpenses(Request $request): void
    {
        abort_unless($request->user()?->can('manage expenses'), 403);
    }
}
