<?php

namespace Database\Factories;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewAssignment>
 */
class CrewAssignmentFactory extends Factory
{
    protected $model = CrewAssignment::class;

    public function definition(): array
    {
        return [
            'crew_id' => Crew::factory(),
            'employee_id' => Employee::factory(),
            'date_assigned' => null,
            'status' => 'active',
        ];
    }
}
