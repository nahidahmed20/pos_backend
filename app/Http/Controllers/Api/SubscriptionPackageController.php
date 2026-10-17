<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => SubscriptionPackage::latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_branches' => ['sometimes', 'integer', 'min:1'],
            'max_products' => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $package = SubscriptionPackage::create($data);

        return response()->json(['data' => $package->refresh()], 201);
    }

    public function show(Request $request, int $package): JsonResponse
    {
        return response()->json(['data' => SubscriptionPackage::findOrFail($package)]);
    }

    public function update(Request $request, int $package): JsonResponse
    {
        $model = SubscriptionPackage::findOrFail($package);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'duration_days' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_branches' => ['sometimes', 'integer', 'min:1'],
            'max_products' => ['sometimes', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $package): JsonResponse
    {
        $model = SubscriptionPackage::findOrFail($package);
        $model->delete();

        return response()->json(['message' => 'Subscription package deleted successfully.']);
    }
}
