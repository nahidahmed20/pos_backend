<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcommerceReview;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcommerceController extends Controller
{
    /**
     * Get ecommerce orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = Sale::with(['customer:id,name,phone,email,address', 'items.product', 'payments'])
            ->latest('sold_at')
            ->get();

        return response()->json(['data' => $orders]);
    }

    /**
     * Get ecommerce product reviews.
     */
    public function reviews(Request $request): JsonResponse
    {
        $reviews = EcommerceReview::with('product:id,name,sku,image_path')->latest()->get();

        return response()->json(['data' => $reviews]);
    }

    /**
     * Store product review.
     */
    public function storeReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'reviewer_name' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = EcommerceReview::create($data);

        return response()->json(['data' => $review->load('product:id,name')], 201);
    }

    /**
     * Update review status (approve, spam, etc.)
     */
    public function updateReview(Request $request, int $review): JsonResponse
    {
        $item = EcommerceReview::findOrFail($review);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,approved,spam'],
        ]);

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    /**
     * Delete review.
     */
    public function destroyReview(Request $request, int $review): JsonResponse
    {
        $item = EcommerceReview::findOrFail($review);
        $item->delete();

        return response()->json(['message' => 'Review deleted successfully.']);
    }
}
