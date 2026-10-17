<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Subscription::with(['company:id,name,email', 'package:id,name,price,duration_days'])->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'package_id' => ['required', 'integer', 'exists:subscription_packages,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'status' => ['sometimes', 'string', 'in:active,expired,cancelled'],
        ]);

        $sub = Subscription::create($data);

        return response()->json(['data' => $sub->refresh()->load(['company:id,name', 'package:id,name'])], 201);
    }

    public function show(Request $request, int $subscription): JsonResponse
    {
        return response()->json([
            'data' => Subscription::with(['company', 'package'])->findOrFail($subscription),
        ]);
    }

    public function update(Request $request, int $subscription): JsonResponse
    {
        $model = Subscription::findOrFail($subscription);
        $data = $request->validate([
            'package_id' => ['sometimes', 'required', 'integer', 'exists:subscription_packages,id'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'string', 'in:active,expired,cancelled'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load(['company:id,name', 'package:id,name'])]);
    }

    public function destroy(Request $request, int $subscription): JsonResponse
    {
        $model = Subscription::findOrFail($subscription);
        $model->delete();

        return response()->json(['message' => 'Subscription deleted successfully.']);
    }
}
