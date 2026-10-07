<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->word(),
            'code' => fake()->unique()->regexify('[a-z]{6}[0-9]{3}'),
            'is_active' => true,
        ];
    }
}
