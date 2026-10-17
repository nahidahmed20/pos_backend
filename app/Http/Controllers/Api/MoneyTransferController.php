<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MoneyTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MoneyTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MoneyTransfer::with(['fromAccount:id,name,bank_name', 'toAccount:id,name,bank_name', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);

        $transfer = DB::transaction(function () use ($data, $request) {
            $from = Account::findOrFail($data['from_account_id']);
            $to = Account::findOrFail($data['to_account_id']);

            $from->decrement('current_balance', $data['amount']);
            $to->increment('current_balance', $data['amount']);

            return MoneyTransfer::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'data' => $transfer->load(['fromAccount:id,name,bank_name', 'toAccount:id,name,bank_name', 'creator:id,name']),
        ], 201);
    }

    public function show(Request $request, int $moneyTransfer): JsonResponse
    {
        $transfer = MoneyTransfer::with(['fromAccount:id,name,bank_name', 'toAccount:id,name,bank_name', 'creator:id,name'])
            ->findOrFail($moneyTransfer);

        return response()->json(['data' => $transfer]);
    }

    public function destroy(Request $request, int $moneyTransfer): JsonResponse
    {
        $transfer = MoneyTransfer::findOrFail($moneyTransfer);

        DB::transaction(function () use ($transfer) {
            $from = Account::find($transfer->from_account_id);
            $to = Account::find($transfer->to_account_id);

            if ($from) {
                $from->increment('current_balance', $transfer->amount);
            }
            if ($to) {
                $to->decrement('current_balance', $transfer->amount);
            }

            $transfer->delete();
        });

        return response()->json(['message' => 'Money transfer reverted and deleted successfully.']);
    }
}
