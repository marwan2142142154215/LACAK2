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
            'name' => fake()->city(),
            'code' => fake()->unique()->regexify('[a-z]{6}[0-9]{3}'),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
