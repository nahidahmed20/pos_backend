<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Company::with(['activeSubscription.package', 'domainRequests'])->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:companies,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive,pending'],
        ]);

        $company = Company::create($data);

        return response()->json(['data' => $company->refresh()], 201);
    }

    public function show(Request $request, int $company): JsonResponse
    {
        return response()->json([
            'data' => Company::with(['subscriptions.package', 'domainRequests', 'transactions'])->findOrFail($company),
        ]);
    }

    public function update(Request $request, int $company): JsonResponse
    {
        $model = Company::findOrFail($company);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', "unique:companies,email,{$model->id}"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive,pending'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $company): JsonResponse
    {
        $model = Company::findOrFail($company);
        $model->delete();

        return response()->json(['message' => 'Company deleted successfully.']);
    }
}
