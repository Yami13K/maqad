<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Center;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Center> */
final class CenterFactory extends Factory
{
    protected $model = Center::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'commission_rate' => '0.1500',
        ];
    }
}
