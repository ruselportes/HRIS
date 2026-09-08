<?php

namespace Database\Factories;

use App\Models\Crew;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crew>
 */
class CrewFactory extends Factory
{
    protected $model = Crew::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'foreman_id' => null,
            'crew_name' => ucfirst($this->faker->word()).' crew '.strtoupper($this->faker->randomLetter()),
            'status' => 'draft',
            'deployed_at' => null,
        ];
    }

    public function withForeman(string $roleSlug = 'foreman'): static
    {
        return $this->state(fn () => [
            'foreman_id' => Employee::factory()->create([
                'role_id' => Role::where('slug', $roleSlug)->first()?->role_id,
            ])->employee_id,
        ]);
    }
}
