<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\SessionStatus;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Center;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seed topology (assumption, recorded in README): 2 centers, each with one Damascus
 * branch and one Muscat branch. Each center runs one course at both of its branches
 * at different prices (BR-03), giving 4 sessions across both time zones (BR-08).
 */
class CatalogSeeder extends Seeder
{
    private const array CENTERS = [
        [
            'name' => 'Noor Training Center',
            'commission_rate' => '0.1000',
            'course' => ['title' => 'Laravel Fundamentals', 'duration_minutes' => 120],
            'sessions' => [
                ['city' => 'Damascus', 'days' => 3, 'local_time' => '18:00', 'capacity' => 10, 'price' => '50.000'],
                ['city' => 'Muscat', 'days' => 4, 'local_time' => '18:00', 'capacity' => 10, 'price' => '65.500'],
            ],
        ],
        [
            'name' => 'Masar Academy',
            'commission_rate' => '0.1500',
            'course' => ['title' => 'PostgreSQL in Practice', 'duration_minutes' => 90],
            'sessions' => [
                ['city' => 'Damascus', 'days' => 5, 'local_time' => '10:00', 'capacity' => 20, 'price' => '40.000'],
                ['city' => 'Muscat', 'days' => 6, 'local_time' => '10:00', 'capacity' => 20, 'price' => '42.125'],
            ],
        ],
    ];

    private const array TIMEZONES = [
        'Damascus' => 'Asia/Damascus',
        'Muscat' => 'Asia/Muscat',
    ];

    public function run(): void
    {
        foreach (self::CENTERS as $spec) {
            $center = Center::query()->create([
                'name' => $spec['name'],
                'commission_rate' => $spec['commission_rate'],
            ]);

            $branches = [];
            foreach (self::TIMEZONES as $city => $timezone) {
                $branches[$city] = Branch::query()->create([
                    'center_id' => $center->id,
                    'name' => "{$spec['name']} {$city}",
                    'city' => $city,
                    'timezone' => $timezone,
                ]);
            }

            $course = Course::query()->create([
                'center_id' => $center->id,
                ...$spec['course'],
            ]);

            foreach ($spec['sessions'] as $session) {
                $branch = $branches[$session['city']];

                // Wall-clock time in the branch's zone, stored as a UTC instant (C10).
                $startsAt = CarbonImmutable::now($branch->timezone)
                    ->addDays($session['days'])
                    ->setTimeFromTimeString($session['local_time'])
                    ->utc();

                CourseSession::query()->create([
                    'course_id' => $course->id,
                    'branch_id' => $branch->id,
                    'center_id' => $center->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($course->duration_minutes),
                    'capacity' => $session['capacity'],
                    'price' => $session['price'],
                    'status' => SessionStatus::Scheduled,
                ]);
            }
        }
    }
}
