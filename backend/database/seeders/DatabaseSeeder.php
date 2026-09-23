<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's modules in dependency order.
     */
    public function run(): void
    {
        $this->call([
            CoreDatabaseSeeder::class,
            AdminDatabaseSeeder::class,
        ]);
    }
}
