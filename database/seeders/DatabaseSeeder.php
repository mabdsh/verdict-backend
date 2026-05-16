<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| Top-level database seeder
|--------------------------------------------------------------------------
|
| Runs all the project's seeders. Invoke with:
|
|   php artisan db:seed
|
| Or run an individual seeder:
|
|   php artisan db:seed --class=DefaultSettingsSeeder
|
| All seeders here are idempotent (INSERT OR IGNORE) so running twice is
| safe. This is important because the cutover path (Node → Laravel) may
| already have the settings populated from the Node backend's
| seedDefaults() and we don't want to clobber operator changes.
*/

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DefaultSettingsSeeder::class,
        ]);
    }
}
