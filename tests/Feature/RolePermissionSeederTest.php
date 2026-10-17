<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;

it('seeds global super admin and branch scoped admin access', function () {
    app(RolePermissionSeeder::class)->run();

    $mainBranch = Branch::where('code', 'MAIN')->firstOrFail();
    $secondBranch = Branch::create([
        'name' => 'Second Branch',
        'code' => 'SECOND',
    ]);

    $superAdmin = User::where('email', 'admin@gmail.com')->firstOrFail();
    $branchAdmin = User::where('email', 'branchadmin@gmail.com')->firstOrFail();

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->hasAllPermissions(Permission::all()))->toBeTrue()
        ->and($superAdmin->hasBranchAccessTo($mainBranch))->toBeTrue()
        ->and($superAdmin->hasBranchAccessTo($secondBranch))->toBeTrue()
        ->and($branchAdmin->hasRole('admin'))->toBeTrue()
        ->and($branchAdmin->hasAllPermissions(Permission::all()))->toBeTrue()
        ->and($branchAdmin->hasBranchAccessTo($mainBranch))->toBeTrue()
        ->and($branchAdmin->hasBranchAccessTo($secondBranch))->toBeFalse();
});
