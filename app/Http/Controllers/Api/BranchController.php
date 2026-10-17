<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can('manage branches') || $request->user()?->can('manage products'),
            403,
        );

        $branches = Branch::query()->where('is_active', true);
        if (! $request->user()->isSuperAdmin() && ! $request->user()->can('manage products')) {
            $branches->whereKey($request->user()->branch_id);
        }

        return response()->json(['data' => $branches->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:branches,code'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'timezone' => ['sometimes', 'required', 'string', 'max:255'],
            'invoice_prefix' => ['sometimes', 'required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => Branch::create($data)->refresh()], 201);
    }

    public function show(Request $request, int $branch): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json(['data' => $this->findFor($request, $branch)]);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        $this->authorizeManagement($request);
        $model = $this->findFor($request, $branch);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'code')->ignore($model),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'logo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'timezone' => ['sometimes', 'required', 'string', 'max:255'],
            'invoice_prefix' => ['sometimes', 'required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    private function queryFor(Request $request): Builder
    {
        return Branch::query()->when(
            ! $request->user()->isSuperAdmin(),
            fn (Builder $query) => $query->whereKey($request->user()->branch_id),
        );
    }

    private function findFor(Request $request, int $id): Branch
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->can('manage branches'), 403);
    }
}
