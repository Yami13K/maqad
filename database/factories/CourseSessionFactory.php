<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\SessionStatus;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Course and branch always belong to the session's center (the C1 composite FKs would
 * reject anything else).
 *
 * @extends Factory<CourseSession>
 */
final class CourseSessionFactory extends Factory
{
    protected $model = CourseSession::class;

    public function definition(): array
    {
        // Fixed and in the future, so tests are deterministic with a frozen or a real clock.
        $startsAt = CarbonImmutable::parse('2027-01-15 15:00:00', 'UTC');

        return [
            'branch_id' => Branch::factory(),
            // Closures see earlier keys already resolved, so branch_id must come first.
            'center_id' => fn (array $a): int => Branch::query()->whereKey($a['branch_id'])->value('center_id'),
            'course_id' => fn (array $a): int => Course::factory()->create(['center_id' => $a['center_id']])->id,
            'starts_at' => $startsAt,
            'ends_at' => fn (array $a): CarbonImmutable => CarbonImmutable::parse($a['starts_at'])->addHour(),
            'capacity' => 10,
            'confirmed_count' => 0,
            'price' => '50.000',
            'status' => SessionStatus::Scheduled,
        ];
    }

    public function at(Branch $branch): self
    {
        return $this->state(['branch_id' => $branch->id, 'center_id' => $branch->center_id]);
    }

    /** Branch-local wall-clock start (e.g. '2026-12-01 18:00' in Asia/Muscat), stored as its UTC instant (C10). */
    public function startingAtLocal(string $localDateTime, string $timezone, int $minutes = 60): self
    {
        $startsAt = CarbonImmutable::parse($localDateTime, $timezone)->utc();

        return $this->state(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($minutes)]);
    }

    public function startingAt(CarbonImmutable $startsAt, int $minutes = 60): self
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($minutes)]);
    }
}
