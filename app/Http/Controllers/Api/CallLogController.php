<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = CallLog::where('user_id', $request->user()->id)->latest('called_at')->get();

        return response()->json(['data' => $logs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'call_type' => ['required', 'string', 'in:incoming,outgoing,missed,video'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'called_at' => ['sometimes', 'date'],
        ]);

        $log = CallLog::create([
            ...$data,
            'called_at' => $data['called_at'] ?? now(),
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
        ]);

        return response()->json(['data' => $log], 201);
    }

    public function show(Request $request, int $callLog): JsonResponse
    {
        $item = CallLog::where('user_id', $request->user()->id)->findOrFail($callLog);

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $request, int $callLog): JsonResponse
    {
        $item = CallLog::where('user_id', $request->user()->id)->findOrFail($callLog);
        $item->delete();

        return response()->json(['message' => 'Call log deleted successfully.']);
    }
}
