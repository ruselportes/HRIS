<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

class ReferenceController extends Controller
{
    /**
     * GET /api/roles — role options for filters and the add-employee form.
     */
    public function roles(): JsonResponse
    {
        return response()->json([
            'roles' => Role::orderBy('role_id')->get(['role_id', 'role_name', 'slug']),
        ]);
    }

    /**
     * GET /api/sites — site options for filters and the add-employee form.
     */
    public function sites(): JsonResponse
    {
        return response()->json([
            'sites' => Site::orderBy('site_id')->get(['site_id', 'site_name', 'location', 'status']),
        ]);
    }
}
