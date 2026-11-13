<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Center;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Runs on every `docker compose up`, so it seeds only an empty database.
     */
    public function run(): void
    {
        if (Center::query()->exists()) {
            $this->command?->info('Database already seeded, skipping.');

            return;
        }

        $this->call([
            CatalogSeeder::class,
            StudentSeeder::class,
        ]);
    }
}
