<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashMovementController extends Controller
{
    public function index(Request $request, int $cashRegisterSession): JsonResponse
    {
        $this->authorizePos($request);
        $session = $this->sessionFor($request, $cashRegisterSession);

        return response()->json([
            'data' => $session->movements()
                ->with('user:id,name')
                ->latest()
                ->paginate(50),
        ]);
    }

    public function store(Request $request, int $cashRegisterSession): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'direction' => ['required', 'string', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($data, $cashRegisterSession, $request) {
            $session = $this->sessionQueryFor($request)
                ->whereKey($cashRegisterSession)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $session->status === 'open',
                422,
                'Cash movements can only be recorded for an open session.',
            );

            return $session->movements()->create([
                'user_id' => $request->user()->id,
                'direction' => $data['direction'],
                'category' => 'manual',
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
            ]);
        });

        return response()->json(['data' => $movement->load('user:id,name')], 201);
    }

    private function sessionFor(Request $request, int $id): CashRegisterSession
    {
        return $this->sessionQueryFor($request)->findOrFail($id);
    }

    private function sessionQueryFor(Request $request): Builder
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

    private function authorizePos(Request $request): void
    {
        abort_unless($request->user()?->can('manage pos'), 403);
    }
}
