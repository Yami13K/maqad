<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Center;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'name' => fake()->streetName(),
            'city' => 'Damascus',
            'timezone' => 'Asia/Damascus',
        ];
    }

    public function damascus(): self
    {
        return $this->state(['city' => 'Damascus', 'timezone' => 'Asia/Damascus']);
    }

    public function muscat(): self
    {
        return $this->state(['city' => 'Muscat', 'timezone' => 'Asia/Muscat']);
    }
}
