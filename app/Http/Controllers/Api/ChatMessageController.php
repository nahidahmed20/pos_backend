<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $users = User::query()
            ->with(['roles:id,name', 'branch:id,name'])
            ->when(! $currentUser->isSuperAdmin(), function (Builder $query) use ($currentUser): void {
                $query->where('branch_id', $currentUser->branch_id);
            })
            ->where('id', '!=', $currentUser->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id']);

        return response()->json(['data' => $users]);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $otherUserId = $request->query('user_id');

        $messages = ChatMessage::query()
            ->when($otherUserId, function ($q) use ($userId, $otherUserId): void {
                $q->where(function ($sub) use ($userId, $otherUserId): void {
                    $sub->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                })->orWhere(function ($sub) use ($userId, $otherUserId): void {
                    $sub->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                });
            }, function ($q) use ($userId): void {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })
            ->with(['sender:id,name', 'receiver:id,name'])
            ->latest()
            ->take(50)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['required_without:file_path', 'nullable', 'string'],
            'file_path' => ['nullable', 'string', 'max:255'],
        ]);

        $message = ChatMessage::create([
            ...$data,
            'sender_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $message->load(['sender:id,name', 'receiver:id,name'])], 201);
    }
}
