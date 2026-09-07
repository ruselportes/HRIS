<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'role_name' => $this->faker->word(),
            'slug' => $this->faker->unique()->slug(1),
            'description' => $this->faker->sentence(),
        ];
    }
}