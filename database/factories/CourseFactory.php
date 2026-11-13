<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Center;
use App\Domain\Catalog\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'center_id' => Center::factory(),
            'title' => fake()->catchPhrase(),
            'duration_minutes' => 60,
        ];
    }
}
