<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Support\ResilientCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    /** One minute: short, because HR edits a record and expects to see it. */
    private const LIST_TTL = 60;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly ResilientCache $cache,
    ) {}

    /**
     * GET /api/employees — filtered, paginated registry.
     *
     * Cached per filter set: the registry is read on every HR screen and
     * written a few times a day. Saving an employee bumps the namespace
     * (AppServiceProvider::INVALIDATES), so an edit shows at once; the ttl
     * only bounds what a missed bump could serve.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = [
            'search' => trim((string) $request->string('search')),
            'role' => (string) $request->string('role'),
            'site_id' => (string) $request->string('site_id'),
            'employment_status' => (string) $request->string('employment_status'),
            'per_page' => min((int) $request->input('per_page', 24), 100),
            'page' => max(1, (int) $request->input('page', 1)),
        ];

        return response()->json($this->cache->remember(
            'employees',
            'index:'.sha1((string) json_encode($filters)),
            self::LIST_TTL,
            fn () => EmployeeResource::collection($this->listQuery($filters)
                ->paginate($filters['per_page'], page: $filters['page']))
                ->response()
                ->getData(true),
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function listQuery(array $filters)
    {
        return Employee::query()
            ->with('role', 'site')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('employee_code', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'] !== '', fn ($query) => $query->whereHas('role', fn ($q) => $q->where('slug', $filters['role'])))
            ->when($filters['site_id'] !== '', fn ($query) => $query->where('site_id', (int) $filters['site_id']))
            ->when($filters['employment_status'] !== '', fn ($query) => $query->where('employment_status', $filters['employment_status']));
    }

    /**
     * GET /api/employees/next-code — next Available employee code.
     */
    public function nextCode(): JsonResponse
    {
        $this->authorize('create', Employee::class);

        return response()->json([
            'employee_code' => $this->employees->nextEmployeeCode(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        $this->authorize('create', Employee::class);

        $data = $request->validated();
        $data['employee_code'] = $data['employee_code'] ?? $this->employees->nextEmployeeCode();

        $employee = Employee::create($data);

        return new EmployeeResource($employee->load('role', 'site'));
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load('role', 'site', 'crewAssignments'));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        $data = $request->validated();

        if (array_key_exists('password', $data) && $data['password'] === null) {
            unset($data['password']);
        }

        $employee->update($data);

        return new EmployeeResource($employee->fresh(['role', 'site']));
    }

    /**
     * POST /api/employees/{employee}/reset-portal-access — Add-on B (FR-11).
     *
     * Recovery for a hijacked or misbehaving portal account. Sets a random
     * temporary password (handed over in person — shown once, in this
     * response), revokes every live token, and is audit-logged. Because a
     * password is then set, self-activation stays refused, so whoever hijacked
     * the account cannot simply re-activate it.
     */
    public function resetPortalAccess(Request $request, Employee $employee): JsonResponse
    {
        if (! $employee->role?->isPortalRole()) {
            throw ValidationException::withMessages([
                'employee' => 'Portal access can only be reset for a worker or operator.',
            ]);
        }

        // Unambiguous: no 0/O, 1/l/I, so an in-person handover is reproducible.
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
        $temporaryPassword = '';
        for ($i = 0; $i < 14; $i++) {
            $temporaryPassword .= $charset[random_int(0, strlen($charset) - 1)];
        }

        // Regular model save applies the hashed cast; a query-builder update
        // would not, which is why the atomic activation hashes explicitly.
        $employee->update(['password' => $temporaryPassword]);
        $employee->tokens()->delete();

        AuditLog::create([
            'actor_id' => $request->user()->employee_id,
            'action_type' => AuditLog::PORTAL_ACCESS_RESET,
            'description' => 'Portal access reset for '.$employee->employee_code.' from '.$request->ip().'.',
            'timestamp' => now(),
        ]);

        return response()->json([
            'temporary_password' => $temporaryPassword,
        ]);
    }
}
