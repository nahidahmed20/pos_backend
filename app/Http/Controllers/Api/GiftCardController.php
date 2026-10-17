<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->queryFor($request)->with('customer:id,name,phone')->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:100', 'unique:gift_cards,card_number'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'pin' => ['nullable', 'string', 'max:10'],
            'amount' => ['required', 'numeric', 'min:1'],
            'expiry_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $card = GiftCard::create([
            ...$data,
            'balance' => $data['amount'],
            'branch_id' => $branchId,
        ]);

        return response()->json(['data' => $card->refresh()->load('customer:id,name,phone')], 201);
    }

    public function show(Request $request, int $giftCard): JsonResponse
    {
        return response()->json([
            'data' => $this->findFor($request, $giftCard)->load('customer:id,name,phone'),
        ]);
    }

    public function update(Request $request, int $giftCard): JsonResponse
    {
        $model = $this->findFor($request, $giftCard);
        $data = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'pin' => ['sometimes', 'nullable', 'string', 'max:10'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('customer:id,name,phone')]);
    }

    public function destroy(Request $request, int $giftCard): JsonResponse
    {
        $model = $this->findFor($request, $giftCard);
        $model->delete();

        return response()->json(['message' => 'Gift card deleted successfully.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => ['required', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $card = GiftCard::where('card_number', $data['card_number'])->where('is_active', true)->first();

        if (! $card) {
            return response()->json(['valid' => false, 'message' => 'Invalid or inactive gift card.'], 404);
        }

        if ($card->expiry_date && now()->gt($card->expiry_date)) {
            return response()->json(['valid' => false, 'message' => 'Gift card has expired.'], 422);
        }

        if ($card->pin && $card->pin !== ($data['pin'] ?? null)) {
            return response()->json(['valid' => false, 'message' => 'Incorrect PIN.'], 422);
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'id' => $card->id,
                'card_number' => $card->card_number,
                'balance' => $card->balance,
            ],
        ]);
    }

    private function queryFor(Request $request): Builder
    {
        return GiftCard::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): GiftCard
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
