<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? null : $request->user()->branch_id;

        $contacts = Contact::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->get();

        return response()->json(['data' => $contacts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = Contact::create([
            ...$data,
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
        ]);

        return response()->json(['data' => $contact], 201);
    }

    public function show(Request $request, int $contact): JsonResponse
    {
        $item = Contact::findOrFail($contact);

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, int $contact): JsonResponse
    {
        $item = Contact::findOrFail($contact);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, int $contact): JsonResponse
    {
        $item = Contact::findOrFail($contact);
        $item->delete();

        return response()->json(['message' => 'Contact deleted successfully.']);
    }
}
