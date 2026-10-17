<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json([
            'data' => $this->queryFor($request)
                ->when(! $request->user()->can('manage branches'), fn (Builder $query) => $query->where('is_active', true))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null, 422, 'A branch is required to create a cash register.');

        $data['branch_id'] = $branchId;
        validator($data, [
            'code' => [Rule::unique('cash_registers', 'code')->where('branch_id', $branchId)],
        ])->validate();

        $register = CashRegister::create($data)->refresh();

        return response()->json(['data' => $register], 201);
    }

    public function show(Request $request, int $cashRegister): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->findFor($request, $cashRegister)]);
    }

    public function update(Request $request, int $cashRegister): JsonResponse
    {
        $this->authorizeManagement($request);
        $register = $this->findFor($request, $cashRegister);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('cash_registers', 'code')
                    ->where('branch_id', $register->branch_id)
                    ->ignore($register),
            ],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $register->update($data);

        return response()->json(['data' => $register->fresh()]);
    }

    public function destroy(Request $request, int $cashRegister): JsonResponse
    {
        $this->authorizeManagement($request);
        $register = $this->findFor($request, $cashRegister);
        abort_if($register->sessions()->exists(), 422, 'Cash registers with session history cannot be deleted.');
        $register->delete();

        return response()->json(['message' => 'Cash register deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return CashRegister::query()->when(
            ! $request->user()->isSuperAdmin(),
            fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id),
        );
    }

    private function findFor(Request $request, int $id): CashRegister
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(
            $request->user()?->can('manage branches') || $request->user()?->can('manage pos'),
            403,
        );
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->can('manage branches'), 403);
    }
}
