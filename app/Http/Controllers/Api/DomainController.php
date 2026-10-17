<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DomainRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => DomainRequest::with('company:id,name,email')->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'domain' => ['required', 'string', 'max:255', 'unique:domain_requests,domain'],
            'notes' => ['nullable', 'string'],
        ]);

        $domain = DomainRequest::create($data);

        return response()->json(['data' => $domain->refresh()->load('company:id,name')], 201);
    }

    public function show(Request $request, int $domain): JsonResponse
    {
        return response()->json(['data' => DomainRequest::with('company')->findOrFail($domain)]);
    }

    public function update(Request $request, int $domain): JsonResponse
    {
        $model = DomainRequest::findOrFail($domain);
        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', 'in:pending,approved,rejected'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()->load('company:id,name')]);
    }

    public function destroy(Request $request, int $domain): JsonResponse
    {
        $model = DomainRequest::findOrFail($domain);
        $model->delete();

        return response()->json(['message' => 'Domain request deleted successfully.']);
    }
}
