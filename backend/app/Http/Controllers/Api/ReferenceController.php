<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Site;
use App\Support\ResilientCache;
use Illuminate\Http\JsonResponse;

/**
 * Reference lists behind filters and forms. They are read on nearly every
 * screen and change a few times a year, so they are cached: a write to Site or
 * Role retires the entry (AppServiceProvider::INVALIDATES), and the ttl bounds
 * anything that slips past that. A cache that cannot answer costs a query,
 * not an error (App\Support\ResilientCache).
 */
class ReferenceController extends Controller
{
    /** Ten minutes: the ceiling on how stale one of these lists can be. */
    private const TTL = 600;

    public function __construct(private readonly ResilientCache $cache) {}

    /**
     * GET /api/roles — role options for filters and the add-employee form.
     */
    public function roles(): JsonResponse
    {
        return response()->json([
            'roles' => $this->cache->remember(
                'reference',
                'roles',
                self::TTL,
                fn () => Role::orderBy('role_id')->get(['role_id', 'role_name', 'slug']),
            ),
        ]);
    }

    /**
     * GET /api/sites — site options for filters and the add-employee form.
     */
    public function sites(): JsonResponse
    {
        return response()->json([
            'sites' => $this->cache->remember(
                'reference',
                'sites',
                self::TTL,
                fn () => Site::orderBy('site_id')->get(['site_id', 'site_name', 'location', 'status']),
            ),
        ]);
    }
}
