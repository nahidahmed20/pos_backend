<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SubscriptionTransaction::with(['company:id,name,email', 'subscription.package:id,name'])->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'string', 'max:50'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:pending,completed,failed'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $transaction = SubscriptionTransaction::create([
            ...$data,
            'paid_at' => $data['paid_at'] ?? now(),
        ]);

        return response()->json(['data' => $transaction->refresh()->load('company:id,name')], 201);
    }

    public function show(Request $request, int $purchaseTransaction): JsonResponse
    {
        return response()->json([
            'data' => SubscriptionTransaction::with(['company', 'subscription.package'])->findOrFail($purchaseTransaction),
        ]);
    }

    public function destroy(Request $request, int $purchaseTransaction): JsonResponse
    {
        $model = SubscriptionTransaction::findOrFail($purchaseTransaction);
        $model->delete();

        return response()->json(['message' => 'Transaction record deleted successfully.']);
    }
}
