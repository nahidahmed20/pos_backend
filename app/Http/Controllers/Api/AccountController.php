<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queryFor($request)->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_number' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['sometimes', 'numeric'],
            'note' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchId($request, $data['branch_id'] ?? null);
        $opening = $data['opening_balance'] ?? 0;

        $account = Account::create([
            ...$data,
            'current_balance' => $opening,
            'branch_id' => $branchId,
        ]);

        return response()->json(['data' => $account->refresh()], 201);
    }

    public function show(Request $request, int $account): JsonResponse
    {
        return response()->json(['data' => $this->findFor($request, $account)]);
    }

    public function update(Request $request, int $account): JsonResponse
    {
        $model = $this->findFor($request, $account);
        $data = $request->validate([
            'account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $account): JsonResponse
    {
        $model = $this->findFor($request, $account);
        $model->delete();

        return response()->json(['message' => 'Bank account deleted successfully.']);
    }

    /**
     * Account Statement: transactions involving this account or cash movements.
     */
    public function statement(Request $request, int $account): JsonResponse
    {
        $model = $this->findFor($request, $account);

        $transfersOut = $model->transfersOut()->with('toAccount:id,name')->get()->map(fn ($t) => [
            'date' => $t->transfer_date,
            'type' => 'Debit (Transfer Out)',
            'description' => "Transferred to {$t->toAccount?->name}",
            'amount' => -$t->amount,
            'reference' => $t->reference,
        ]);

        $transfersIn = $model->transfersIn()->with('fromAccount:id,name')->get()->map(fn ($t) => [
            'date' => $t->transfer_date,
            'type' => 'Credit (Transfer In)',
            'description' => "Transferred from {$t->fromAccount?->name}",
            'amount' => $t->amount,
            'reference' => $t->reference,
        ]);

        $statement = $transfersOut->concat($transfersIn)->sortByDesc('date')->values();

        return response()->json([
            'account' => $model,
            'statement' => $statement,
        ]);
    }

    /**
     * Balance Sheet API.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $accountsBalance = Account::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->sum('current_balance');

        $salesReceivable = Sale::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('due_amount');

        $purchasesPayable = Purchase::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('due_amount');

        $totalAssets = $accountsBalance + $salesReceivable;
        $totalLiabilities = $purchasesPayable;
        $equity = $totalAssets - $totalLiabilities;

        return response()->json([
            'assets' => [
                'bank_and_cash' => (float) $accountsBalance,
                'accounts_receivable' => (float) $salesReceivable,
                'total_assets' => (float) $totalAssets,
            ],
            'liabilities' => [
                'accounts_payable' => (float) $purchasesPayable,
                'total_liabilities' => (float) $totalLiabilities,
            ],
            'equity' => (float) $equity,
        ]);
    }

    /**
     * Trial Balance API.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $sales = Sale::query()->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->sum('total');
        $purchases = Purchase::query()->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->sum('total');
        $expenses = Expense::query()->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->sum('amount');
        $incomes = Income::query()->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->sum('amount');
        $bankBalance = Account::query()->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->sum('current_balance');

        $debitTotal = $purchases + $expenses + $bankBalance;
        $creditTotal = $sales + $incomes;

        return response()->json([
            'debits' => [
                'purchases' => (float) $purchases,
                'expenses' => (float) $expenses,
                'cash_and_bank' => (float) $bankBalance,
                'total' => (float) $debitTotal,
            ],
            'credits' => [
                'sales' => (float) $sales,
                'incomes' => (float) $incomes,
                'total' => (float) $creditTotal,
            ],
        ]);
    }

    /**
     * Cash Flow Statement API.
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $cashReceivedFromSales = Payment::query()
            ->whereNotNull('sale_id')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $otherIncome = Income::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $cashPaidPurchases = Payment::query()
            ->whereNotNull('purchase_id')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $cashPaidExpenses = Expense::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->sum('amount');

        $cashInflow = $cashReceivedFromSales + $otherIncome;
        $cashOutflow = $cashPaidPurchases + $cashPaidExpenses;
        $netCashFlow = $cashInflow - $cashOutflow;

        return response()->json([
            'operating_activities' => [
                'cash_received_sales' => (float) $cashReceivedFromSales,
                'other_income' => (float) $otherIncome,
                'total_inflow' => (float) $cashInflow,
                'cash_paid_purchases' => (float) $cashPaidPurchases,
                'cash_paid_expenses' => (float) $cashPaidExpenses,
                'total_outflow' => (float) $cashOutflow,
                'net_cash_flow' => (float) $netCashFlow,
            ],
        ]);
    }

    private function queryFor(Request $request): Builder
    {
        return Account::query()->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
            });
        });
    }

    private function findFor(Request $request, int $id): Account
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function branchId(Request $request, ?int $requestedBranchId): ?int
    {
        return $request->user()->isSuperAdmin() ? $requestedBranchId : $request->user()->branch_id;
    }
}
