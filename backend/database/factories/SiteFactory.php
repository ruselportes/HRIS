<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'site_name' => $this->faker->company().' Site',
            'location' => $this->faker->city(),
            'status' => 'active',
        ];
    }
}