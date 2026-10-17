<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('view reports'), 403);
        $branchId = $request->user()->isSuperAdmin() ? $request->integer('branch_id') : $request->user()->branch_id;

        $salesQuery = Sale::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $purchasesQuery = Purchase::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $expensesQuery = Expense::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $salesTotal = (float) $salesQuery->sum('total');
        $purchaseTotal = (float) $purchasesQuery->sum('total');
        $expenseTotal = (float) $expensesQuery->sum('amount');
        $salesCount = $salesQuery->count();
        $purchaseCount = $purchasesQuery->count();

        // Today's metrics
        $today = Carbon::today();
        $todaySales = (float) Sale::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('created_at', $today)
            ->sum('total');
        $todayOrders = Sale::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('created_at', $today)
            ->count();
        $todayPurchases = (float) Purchase::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('created_at', $today)
            ->sum('total');

        // Total entities
        $totalCustomers = Customer::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count();
        $totalSuppliers = Supplier::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count();
        $totalProducts = Product::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count();

        // Weekly trend (last 7 days)
        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayName = $date->format('D');
            $dateFormatted = $date->format('M d');

            $daySales = (float) Sale::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('created_at', $date)
                ->sum('total');

            $dayPurchases = (float) Purchase::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('created_at', $date)
                ->sum('total');

            $weeklyTrend[] = [
                'day' => $dayName,
                'date' => $dateFormatted,
                'sales' => $daySales,
                'purchases' => $dayPurchases,
            ];
        }

        // Recent 5 sales with customer
        $recentSales = Sale::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['customer:id,name,phone'])
            ->withCount('items')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'invoice_number' => $s->invoice_number,
                'customer_name' => $s->customer?->name ?? 'Walk-in Customer',
                'customer_phone' => $s->customer?->phone ?? '—',
                'total' => (float) $s->total,
                'paid_amount' => (float) $s->paid_amount,
                'payment_method' => $s->payment_method ?? 'cash',
                'payment_status' => $s->payment_status ?? 'paid',
                'items_count' => $s->items_count,
                'sold_at' => $s->created_at?->format('d M, h:i A') ?? '—',
            ]);

        // Top 5 selling products
        $topProducts = SaleItem::query()
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(total) as total_revenue'))
            ->whereHas('sale', fn ($q) => $q->when($branchId, fn ($sq) => $sq->where('branch_id', $branchId)))
            ->with(['product:id,name,image_path,selling_price,category_id', 'product.category:id,name'])
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? 'Unknown Item',
                'image_path' => $item->product?->image_path ?? null,
                'category' => $item->product?->category?->name ?? 'General',
                'price' => (float) ($item->product?->selling_price ?? 0),
                'quantity_sold' => (int) $item->total_quantity,
                'revenue' => (float) $item->total_revenue,
            ]);

        // Payment methods breakdown
        $paymentBreakdown = Sale::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn ($row) => [
                ($row->payment_method ?: 'cash') => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ],
            ]);

        return response()->json([
            'data' => [
                'sales_total' => number_format($salesTotal, 2, '.', ''),
                'purchase_total' => number_format($purchaseTotal, 2, '.', ''),
                'expense_total' => number_format($expenseTotal, 2, '.', ''),
                'sales_count' => $salesCount,
                'purchase_count' => $purchaseCount,
                'today_sales' => $todaySales,
                'today_orders' => $todayOrders,
                'today_purchases' => $todayPurchases,
                'total_customers' => $totalCustomers,
                'total_suppliers' => $totalSuppliers,
                'total_products' => $totalProducts,
                'weekly_trend' => $weeklyTrend,
                'recent_sales' => $recentSales,
                'top_products' => $topProducts,
                'payment_breakdown' => $paymentBreakdown,
            ],
        ]);
    }
}
