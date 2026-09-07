<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'site_id' => Site::factory(),
            'employee_code' => $this->faker->unique()->numerify('ADC-####'),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'middle_name' => $this->faker->optional()->lastName(),
            'trade_skill' => $this->faker->randomElement(['Formwork', 'Steelwork', 'Rebar', 'Masonry', 'Heavy equipment', 'Welding']),
            'daily_rate' => $this->faker->numberBetween(520, 1200),
            'certification' => [],
            'emergency_contact' => null,
            'employment_status' => $this->faker->randomElement(['probationary', 'regular', 'project_based', 'seasonal']),
            'date_of_birth' => $this->faker->date('Y-m-d', '-25 years'),
            'mobile' => '+63 '.$this->faker->numerify('9## ### ####'),
            'civil_status' => $this->faker->randomElement(['Single', 'Married', 'Widowed']),
            'dependents' => $this->faker->numberBetween(0, 6),
            'address' => $this->faker->streetAddress(),
            'blood_type' => $this->faker->randomElement(['A+', 'B+', 'O+', 'AB+', 'O-']),
            'tin' => $this->faker->numerify('###-###-###'),
            'sss' => $this->faker->numerify('##-#######-#'),
            'philhealth' => $this->faker->numerify('##-#########-#'),
            'pag_ibig' => $this->faker->numerify('####-####-####'),
            'date_hired' => $this->faker->date('Y-m-d', '-5 years'),
            'cost_centre' => $this->faker->randomElement(['CO-01', 'CO-02', 'CO-03', 'CO-04']),
        ];
    }

    public function loginEligible(string $roleSlug): static
    {
        return $this->state(fn () => [
            'role_id' => Role::where('slug', $roleSlug)->first()?->role_id,
            'password' => 'password',
        ]);
    }
}