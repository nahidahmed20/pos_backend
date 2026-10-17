<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', 'string', 'in:fixed,percent'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'min_spend' => ['sometimes', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $coupon = Coupon::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $coupon->refresh()], 201);
    }

    public function show(Request $request, int $coupon): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $coupon)]);
    }

    public function update(Request $request, int $coupon): JsonResponse
    {
        $model = $this->findFor($request, $coupon);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'discount_type' => ['sometimes', 'required', 'string', 'in:fixed,percent'],
            'discount_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'min_spend' => ['sometimes', 'numeric', 'min:0'],
            'max_discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $coupon): JsonResponse
    {
        $model = $this->findFor($request, $coupon);
        $model->delete();

        return response()->json(['message' => 'Coupon deleted successfully.']);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = Coupon::where('code', $data['code'])->where('is_active', true)->first();

        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'Invalid or inactive coupon code.'], 404);
        }

        if ($coupon->start_date && now()->lt($coupon->start_date)) {
            return response()->json(['valid' => false, 'message' => 'Coupon is not yet active.'], 422);
        }

        if ($coupon->end_date && now()->gt($coupon->end_date)) {
            return response()->json(['valid' => false, 'message' => 'Coupon has expired.'], 422);
        }

        if ($coupon->usage_limit && $coupon->usage_count >= $coupon->usage_limit) {
            return response()->json(['valid' => false, 'message' => 'Coupon usage limit reached.'], 422);
        }

        if ($data['subtotal'] < $coupon->min_spend) {
            return response()->json(['valid' => false, 'message' => "Minimum spend required is {$coupon->min_spend}."], 422);
        }

        $discount = $coupon->discount_type === 'percent'
            ? ($data['subtotal'] * ($coupon->discount_value / 100))
            : $coupon->discount_value;

        if ($coupon->max_discount && $discount > $coupon->max_discount) {
            $discount = $coupon->max_discount;
        }

        return response()->json([
            'valid' => true,
            'coupon' => $coupon,
            'discount' => round($discount, 2),
        ]);
    }

    private function queryFor(Request $request): Builder
    {
        return Coupon::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Coupon
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
