<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function publicAppInfo(): JsonResponse
    {
        $settings = Setting::query()
            ->whereNull('branch_id')
            ->orWhere('branch_id', 1)
            ->get()
            ->pluck('value', 'key');

        $branchesCount = Branch::count();
        $todaySales = Sale::whereDate('created_at', today())->sum('total');
        $todayOrders = Sale::whereDate('created_at', today())->count();

        $displaySales = $todaySales > 0 ? (float) $todaySales : 148450;
        $displayOrders = $todayOrders > 0 ? $todayOrders : 94;

        $appName = $settings['app_name'] ?? $settings['company_name'] ?? 'DreamsPOS';

        return response()->json([
            'app_name' => $appName,
            'company_name' => $settings['company_name'] ?? 'DreamsPOS Retail',
            'tagline' => $settings['tagline'] ?? 'RETAIL INTELLIGENCE',
            'logo_url' => $settings['logo_white'] ?? '/assets/img/logo-white.svg',
            'logo_dark' => $settings['logo_normal'] ?? '/assets/img/logo.svg',
            'hero_title' => $settings['login_hero_title'] ?? 'আপনার পুরো ব্যবসা, এক নিপুণ নিয়ন্ত্রণে।',
            'hero_subtitle' => $settings['login_hero_subtitle'] ?? 'দ্রুততম চেকআউট, রিয়েল-টাইম স্টক আপডেট এবং সমন্বিত মাল্টি-ব্রাঞ্চ সেলস ম্যানেজমেন্ট সিস্টেম।',
            'support_email' => $settings['email'] ?? 'support@dreamspos.com',
            'currency_symbol' => $settings['currency_symbol'] ?? '৳',
            'version' => $settings['system_version'] ?? 'DreamsPOS v2.4 Enterprise',
            'copyright' => $settings['copyright_text'] ?? ('© '.date('Y').' '.$appName.' • সর্বস্বত্ব সংরক্ষিত'),
            'live_stats' => [
                'today_sales' => $displaySales,
                'completed_orders' => $displayOrders,
                'connected_branches' => max($branchesCount, 1),
                'print_speed' => '০.৪ সে.',
            ],
            'demo_accounts' => [
                [
                    'role' => 'সুপার অ্যাডমিন',
                    'email' => 'admin@gmail.com',
                    'password' => 'password123',
                ],
                [
                    'role' => 'ব্রাঞ্চ অ্যাডমিন',
                    'email' => 'branchadmin@gmail.com',
                    'password' => 'password123',
                ],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? $request->query('branch_id') : $request->user()->branch_id;

        $settings = Setting::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->get()
            ->pluck('value', 'key');

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'group' => ['sometimes', 'string', 'max:50'],
        ]);

        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        $group = $data['group'] ?? 'general';

        foreach ($data['settings'] as $key => $val) {
            Setting::updateOrCreate([
                'branch_id' => $branchId,
                'key' => (string) $key,
            ], [
                'value' => is_array($val) ? json_encode($val) : (string) $val,
                'group' => $group,
            ]);
        }

        return response()->json(['message' => 'Settings updated successfully.']);
    }
}
