<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $todos = Todo::where('user_id', $request->user()->id)->latest()->get();

        return response()->json(['data' => $todos]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'status' => ['sometimes', 'string', 'in:pending,in_progress,completed'],
        ]);

        $todo = Todo::create([
            ...$data,
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
        ]);

        return response()->json(['data' => $todo], 201);
    }

    public function show(Request $request, int $todo): JsonResponse
    {
        $item = Todo::where('user_id', $request->user()->id)->findOrFail($todo);

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, int $todo): JsonResponse
    {
        $item = Todo::where('user_id', $request->user()->id)->findOrFail($todo);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'status' => ['sometimes', 'string', 'in:pending,in_progress,completed'],
        ]);

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, int $todo): JsonResponse
    {
        $item = Todo::where('user_id', $request->user()->id)->findOrFail($todo);
        $item->delete();

        return response()->json(['message' => 'Todo deleted successfully.']);
    }
}
