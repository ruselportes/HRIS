<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    /**
     * GET /api/employees — filtered, paginated registry.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::query()->with('role', 'site');

        if ($search = trim((string) $request->string('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->whereHas('role', fn ($q) => $q->where('slug', $request->string('role')));
        }

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->integer('site_id'));
        }

        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->string('employment_status'));
        }

        $perPage = min((int) $request->input('per_page', 24), 100);

        return EmployeeResource::collection($query->paginate($perPage));
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
}
