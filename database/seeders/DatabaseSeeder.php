<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * UserSeeder creates dev-only test accounts with known passwords for
     * exercising authenticated endpoints locally. DocumentSeeder creates
     * its own three fixture "author" accounts internally (unusable
     * passwords, FK-target only — see that file).
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DocumentSeeder::class,
        ]);
    }
}
