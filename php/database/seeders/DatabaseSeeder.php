<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // RbacSeeder d'abord : DemoTenantSeeder rattache ses comptes aux roles
        // qu'il vient de creer.
        $this->call([
            RbacSeeder::class,
            DemoTenantSeeder::class,
        ]);
    }
}
