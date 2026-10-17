<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->isSuperAdmin() ? null : $request->user()->branch_id;

        $projects = Project::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->get();

        return response()->json(['data' => $projects]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'budget' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:not_started,in_progress,completed,on_hold'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        $project = Project::create([...$data, 'branch_id' => $branchId]);

        return response()->json(['data' => $project], 201);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        return response()->json(['data' => Project::findOrFail($project)]);
    }

    public function update(Request $request, int $project): JsonResponse
    {
        $model = Project::findOrFail($project);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'budget' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:not_started,in_progress,completed,on_hold'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->fresh()]);
    }

    public function destroy(Request $request, int $project): JsonResponse
    {
        $model = Project::findOrFail($project);
        $model->delete();

        return response()->json(['message' => 'Project deleted successfully.']);
    }
}
