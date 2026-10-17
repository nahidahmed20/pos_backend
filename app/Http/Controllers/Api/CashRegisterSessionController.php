<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePos($request);

        return response()->json([
            'data' => $this->queryFor($request)
                ->with(['cashRegister:id,branch_id,name,code', 'user:id,name', 'closer:id,name'])
                ->latest('opened_at')
                ->paginate(25),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'opening_balance' => ['sometimes', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = DB::transaction(function () use ($data, $request): CashRegisterSession {
            $register = CashRegister::query()
                ->when(
                    ! $request->user()->isSuperAdmin(),
                    fn (Builder $query) => $query->where('branch_id', $request->user()->branch_id),
                )
                ->whereKey($data['cash_register_id'])
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($register->is_active, 422, 'This cash register is inactive.');
            abort_if(
                $register->sessions()->where('status', 'open')->exists(),
                422,
                'This cash register already has an open session.',
            );

            return $register->sessions()->create([
                'user_id' => $request->user()->id,
                'opened_at' => now(),
                'opening_balance' => $data['opening_balance'] ?? 0,
                'opening_note' => $data['opening_note'] ?? null,
                'status' => 'open',
            ]);
        });

        return response()->json([
            'data' => $session->load(['cashRegister:id,branch_id,name,code', 'user:id,name']),
        ], 201);
    }

    public function show(Request $request, int $cashRegisterSession): JsonResponse
    {
        $this->authorizePos($request);

        return response()->json([
            'data' => $this->findFor($request, $cashRegisterSession)
                ->load(['cashRegister:id,branch_id,name,code', 'user:id,name', 'closer:id,name', 'movements']),
        ]);
    }

    public function close(Request $request, int $cashRegisterSession): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'closing_balance' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = DB::transaction(function () use ($data, $cashRegisterSession, $request): CashRegisterSession {
            $session = $this->queryFor($request)
                ->whereKey($cashRegisterSession)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($session->status === 'open', 422, 'This cash register session is already closed.');

            $cashIn = (float) $session->movements()->where('direction', 'in')->sum('amount');
            $cashOut = (float) $session->movements()->where('direction', 'out')->sum('amount');
            $expectedBalance = round((float) $session->opening_balance + $cashIn - $cashOut, 2);
            $closingBalance = round((float) $data['closing_balance'], 2);

            $session->update([
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'closing_balance' => $closingBalance,
                'expected_balance' => $expectedBalance,
                'difference' => round($closingBalance - $expectedBalance, 2),
                'closing_note' => $data['closing_note'] ?? null,
                'status' => 'closed',
            ]);

            return $session;
        });

        return response()->json([
            'data' => $session->fresh()->load(['cashRegister:id,branch_id,name,code', 'user:id,name', 'closer:id,name']),
        ]);
    }

    private function queryFor(Request $request): Builder
    {
        return CashRegisterSession::query()
            ->whereHas('cashRegister', function (Builder $query) use ($request): void {
                if (! $request->user()->isSuperAdmin()) {
                    $query->where('branch_id', $request->user()->branch_id);
                }
            })
            ->when(
                ! $request->user()->isSuperAdmin() && ! $request->user()->can('manage branches'),
                fn (Builder $query) => $query->where('user_id', $request->user()->id),
            );
    }

    private function findFor(Request $request, int $id): CashRegisterSession
    {
        return $this->queryFor($request)->findOrFail($id);
    }

    private function authorizePos(Request $request): void
    {
        abort_unless($request->user()?->can('manage pos'), 403);
    }
}
