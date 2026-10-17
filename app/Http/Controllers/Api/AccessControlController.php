<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccessControlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json([
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name', 'guard_name']),
            'roles' => Role::query()->with('permissions:id,name')->orderBy('name')->get(['id', 'name', 'guard_name']),
            'users' => $this->usersQuery($request)->latest()->get(['id', 'name', 'email', 'branch_id']),
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json(['data' => Permission::query()->orderBy('name')->get(['id', 'name', 'guard_name'])]);
    }

    public function storePermission(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name'],
        ]);

        $permission = Permission::create(['name' => $data['name'], 'guard_name' => 'web']);

        return response()->json(['data' => $permission], 201);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json([
            'data' => Role::query()->with('permissions:id,name')->orderBy('name')->get(['id', 'name', 'guard_name']),
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = DB::transaction(function () use ($data): Role {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);

            return $role->load('permissions:id,name');
        });

        return response()->json(['data' => $role], 201);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json([
            'data' => $this->usersQuery($request)->latest()->get(['id', 'name', 'email', 'branch_id']),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $request->user()->isSuperAdmin() ? ($data['branch_id'] ?? null) : $request->user()->branch_id;
        abort_unless($branchId !== null || ($request->user()->isSuperAdmin() && $data['role'] === 'super-admin'), 422);
        abort_unless($request->user()->isSuperAdmin() || $data['role'] !== 'super-admin', 403);
        if ($data['role'] === 'super-admin') {
            $branchId = null;
        }

        $user = DB::transaction(function () use ($data, $branchId): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'branch_id' => $branchId,
            ]);
            $user->syncRoles([$data['role']]);

            return $user->load(['roles:id,name', 'branch:id,name']);
        });

        return response()->json(['data' => $user], 201);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->can('manage users'), 403);
    }

    private function usersQuery(Request $request): Builder
    {
        return User::query()
            ->with(['roles:id,name', 'branch:id,name'])
            ->when(! $request->user()->isSuperAdmin(), function (Builder $query) use ($request): void {
                $query->where('branch_id', $request->user()->branch_id);
            });
    }
}
