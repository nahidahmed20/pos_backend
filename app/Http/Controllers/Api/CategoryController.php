<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->queryFor($request)->with('parent:id,name')->orderBy('name')->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeProducts($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $this->validateParent($data['parent_id'] ?? null, $branchId);
        if (! empty($data['slug'])) {
            validator($data, [
                'slug' => [Rule::unique('categories', 'slug')->where('branch_id', $branchId)],
            ])->validate();
        }
        abort_unless(! Category::where('branch_id', $branchId)->where('name', $data['name'])->exists(), 422, 'Category already exists in this branch.');

        $category = Category::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $category], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $category): JsonResponse
    {
        $this->authorizeProducts($request);

        return response()->json(['data' => $this->findFor($request, $category)->load('parent:id,name')]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $category): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->findFor($request, $category);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ]);
        if (array_key_exists('parent_id', $data)) {
            $this->validateParent($data['parent_id'], $model->branch_id, $model);
        }
        if (isset($data['name'])) {
            abort_unless(! Category::where('branch_id', $model->branch_id)->where('name', $data['name'])->where('id', '!=', $model->id)->exists(), 422, 'Category already exists in this branch.');
        }
        if (! empty($data['slug'])) {
            validator($data, [
                'slug' => [
                    Rule::unique('categories', 'slug')
                        ->where('branch_id', $model->branch_id)
                        ->ignore($model),
                ],
            ])->validate();
        }
        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('parent:id,name')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $category): JsonResponse
    {
        $this->authorizeProducts($request);
        $model = $this->findFor($request, $category);
        abort_if($model->children()->exists(), 422, 'Delete child categories before deleting this parent category.');
        abort_if($model->products()->exists(), 422, 'Move or delete products before deleting this category.');
        $model->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    private function queryFor(Request $request): Builder
    {
        return Category::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where('branch_id', $request->user()->branch_id);
        });
    }

    private function findFor(Request $request, int $id): Category
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): int
    {
        $branchId = $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
        abort_unless($branchId !== null, 422);

        return $branchId;
    }

    private function authorizeProducts(Request $request): void
    {
        abort_unless($request->user()?->can('manage products'), 403);
    }

    private function validateParent(?int $parentId, int $branchId, ?Category $category = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Category::findOrFail($parentId);
        abort_unless($parent->branch_id === $branchId, 422, 'Parent category must belong to the same branch.');
        abort_unless($category?->id !== $parent->id, 422, 'A category cannot be its own parent.');

        $ancestorId = $parent->parent_id;
        while ($ancestorId !== null) {
            abort_if($ancestorId === $category?->id, 422, 'A category cannot be moved beneath its own child.');
            $ancestorId = Category::whereKey($ancestorId)->value('parent_id');
        }
    }
}
