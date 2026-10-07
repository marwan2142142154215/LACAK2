<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $team = Team::factory()->create();

        return [
            'site_id' => $team->site_id,
            'team_id' => $team->id,
            'name' => 'Device '.fake()->bothify('??####'),
            'status' => 'UNKNOWN',
            'is_managed' => false,
            'is_active' => true,
        ];
    }
}
