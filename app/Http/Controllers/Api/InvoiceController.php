<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $invoices = Sale::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->with(['customer:id,name,phone,email,address', 'creator:id,name', 'payments'])
            ->latest('sold_at')
            ->get();

        return response()->json(['data' => $invoices]);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? null : $request->user()->branch_id;

        $saleModel = Sale::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->with([
                'branch',
                'customer',
                'creator:id,name,email',
                'items.product',
                'items.variant',
                'payments',
                'returns',
            ])
            ->findOrFail($sale);

        $settings = Setting::query()
            ->when($saleModel->branch_id, fn ($q) => $q->where('branch_id', $saleModel->branch_id)->orWhereNull('branch_id'))
            ->get()
            ->pluck('value', 'key');

        return response()->json([
            'data' => [
                'sale' => $saleModel,
                'store_info' => [
                    'name' => $settings['company_name'] ?? $saleModel->branch?->name ?? 'POS Store',
                    'phone' => $settings['phone'] ?? $saleModel->branch?->phone ?? '',
                    'email' => $settings['email'] ?? $saleModel->branch?->email ?? '',
                    'address' => $settings['address'] ?? $saleModel->branch?->address ?? '',
                    'receipt_header' => $settings['receipt_header'] ?? 'Thank you for shopping with us!',
                    'receipt_footer' => $settings['receipt_footer'] ?? 'Visit again!',
                ],
            ],
        ]);
    }
}
