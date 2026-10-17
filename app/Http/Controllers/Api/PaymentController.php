<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $payments = Payment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['sale:id,invoice_number,total', 'purchase:id,invoice_number,total', 'customer:id,name,phone', 'receiver:id,name'])
            ->latest('paid_at')
            ->get();

        return response()->json(['data' => $payments]);
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? null : $request->user()->branch_id;

        $paymentModel = Payment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['sale', 'purchase', 'customer', 'branch', 'receiver:id,name,email'])
            ->findOrFail($payment);

        return response()->json(['data' => $paymentModel]);
    }

    public function store(Request $request, int $sale): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'in:cash,card,mobile_banking'], 'reference' => ['nullable', 'string', 'max:255'], 'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id']]);
        $payment = DB::transaction(function () use ($data, $sale, $request): Payment {
            $saleModel = Sale::whereKey($sale)
                ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((float) $data['amount'] <= (float) $saleModel->due_amount, 422, 'Payment exceeds due amount.');
            $cashSession = null;
            if (! empty($data['cash_register_session_id'])) {
                abort_unless($data['method'] === 'cash', 422, 'A cash session can only be attached to a cash payment.');
                $cashSession = CashRegisterSession::query()
                    ->whereKey($data['cash_register_session_id'])
                    ->where('status', 'open')
                    ->whereHas('cashRegister', fn ($query) => $query->where('branch_id', $saleModel->branch_id))
                    ->when(
                        ! $request->user()->isSuperAdmin() && ! $request->user()->can('manage branches'),
                        fn ($query) => $query->where('user_id', $request->user()->id),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $paidAmount = round((float) $saleModel->paid_amount + (float) $data['amount'], 2);
            $dueAmount = round((float) $saleModel->due_amount - (float) $data['amount'], 2);
            $saleModel->update([
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $dueAmount <= 0 ? 'paid' : 'partial',
                'payment_method' => $data['method'],
            ]);
            if ($cashSession !== null && $saleModel->cash_register_session_id === null) {
                $saleModel->update([
                    'cash_register_id' => $cashSession->cash_register_id,
                    'cash_register_session_id' => $cashSession->id,
                ]);
            }
            $payment = Payment::create([
                'sale_id' => $saleModel->id,
                'branch_id' => $saleModel->branch_id,
                'customer_id' => $saleModel->customer_id,
                'received_by' => $request->user()->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_at' => now(),
            ]);
            if ($cashSession !== null) {
                CashMovement::create([
                    'cash_register_session_id' => $cashSession->id,
                    'user_id' => $request->user()->id,
                    'direction' => 'in',
                    'category' => 'sale_payment',
                    'amount' => $data['amount'],
                    'description' => "Payment for sale {$saleModel->invoice_number}",
                    'reference_type' => Payment::class,
                    'reference_id' => $payment->id,
                ]);
            }
            $payment->setRelation('sale', $saleModel);

            return $payment;
        });

        return response()->json(['data' => $payment->load('sale')], 201);
    }

    public function storeForPurchase(Request $request, int $purchase): JsonResponse
    {
        abort_unless($request->user()?->can('manage purchases'), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'in:cash,card,mobile_banking,bank_transfer,cheque'],
            'reference' => ['nullable', 'string', 'max:255'],
            'cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
        ]);
        $payment = DB::transaction(function () use ($data, $purchase, $request): Payment {
            $purchaseModel = Purchase::whereKey($purchase)
                ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((float) $data['amount'] <= (float) $purchaseModel->due_amount, 422, 'Payment exceeds due amount.');
            $cashSession = null;
            if (! empty($data['cash_register_session_id'])) {
                abort_unless($data['method'] === 'cash', 422, 'A cash session can only be attached to a cash payment.');
                $cashSession = CashRegisterSession::query()
                    ->whereKey($data['cash_register_session_id'])
                    ->where('status', 'open')
                    ->whereHas('cashRegister', fn ($query) => $query->where('branch_id', $purchaseModel->branch_id))
                    ->when(
                        ! $request->user()->isSuperAdmin() && ! $request->user()->can('manage branches'),
                        fn ($query) => $query->where('user_id', $request->user()->id),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $paidAmount = round((float) $purchaseModel->paid_amount + (float) $data['amount'], 2);
            $dueAmount = round((float) $purchaseModel->due_amount - (float) $data['amount'], 2);
            $purchaseModel->update([
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $dueAmount <= 0 ? 'paid' : 'partial',
                'payment_method' => $data['method'],
                'reference' => $data['reference'] ?? $purchaseModel->reference,
            ]);
            $payment = Payment::create([
                'purchase_id' => $purchaseModel->id,
                'branch_id' => $purchaseModel->branch_id,
                'received_by' => $request->user()->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_at' => now(),
            ]);
            if ($cashSession !== null) {
                CashMovement::create([
                    'cash_register_session_id' => $cashSession->id,
                    'user_id' => $request->user()->id,
                    'direction' => 'out',
                    'category' => 'purchase_payment',
                    'amount' => $data['amount'],
                    'description' => "Payment for purchase {$purchaseModel->invoice_number}",
                    'reference_type' => Payment::class,
                    'reference_id' => $payment->id,
                ]);
            }
            $payment->setRelation('purchase', $purchaseModel);

            return $payment;
        });

        return response()->json(['data' => $payment->load('purchase')], 201);
    }

    private function authorizePos(Request $request): void
    {
        abort_unless($request->user()?->can('manage pos'), 403);
    }
}
