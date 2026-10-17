<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Return structured email mailbox items
        return response()->json([
            'data' => [
                [
                    'id' => 1,
                    'from' => 'support@example.com',
                    'subject' => 'Welcome to the POS System',
                    'snippet' => 'Welcome to your new point-of-sale management platform...',
                    'date' => now()->subDay()->toDateTimeString(),
                    'is_read' => true,
                    'folder' => 'inbox',
                ],
                [
                    'id' => 2,
                    'from' => 'billing@supplier.com',
                    'subject' => 'Invoice #1042 Available',
                    'snippet' => 'Your recent purchase order has been processed...',
                    'date' => now()->subHours(4)->toDateTimeString(),
                    'is_read' => false,
                    'folder' => 'inbox',
                ],
            ],
        ]);
    }
}
