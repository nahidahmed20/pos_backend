<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notes = Note::where('user_id', $request->user()->id)->latest()->get();

        return response()->json(['data' => $notes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'tag' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note = Note::create([
            ...$data,
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
        ]);

        return response()->json(['data' => $note], 201);
    }

    public function show(Request $request, int $note): JsonResponse
    {
        $item = Note::where('user_id', $request->user()->id)->findOrFail($note);

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, int $note): JsonResponse
    {
        $item = Note::where('user_id', $request->user()->id)->findOrFail($note);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, int $note): JsonResponse
    {
        $item = Note::where('user_id', $request->user()->id)->findOrFail($note);
        $item->delete();

        return response()->json(['message' => 'Note deleted successfully.']);
    }
}
