<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['site_name' => 'Site 01 — Mactan Industrial', 'location' => 'Lapu-Lapu, Cebu'],
            ['site_name' => 'Site 02 — Lapu-Lapu Terminal', 'location' => 'Lapu-Lapu, Cebu'],
            ['site_name' => 'Site 03 — Banilad Bypass', 'location' => 'Cebu City, Cebu'],
            ['site_name' => 'Site 04 — Cebu North', 'location' => 'Consolacion, Cebu'],
            ['site_name' => 'Site 05 — Carcar North Link', 'location' => 'Carcar, Cebu'],
            ['site_name' => 'Site 06 — Minglanilla Industrial', 'location' => 'Minglanilla, Cebu'],
            ['site_name' => 'Site 07 — Mandaue Viaduct', 'location' => 'Mandaue, Cebu'],
            ['site_name' => 'Site 08 — Talamban Hills', 'location' => 'Cebu City, Cebu'],
            ['site_name' => 'Site 09 — Naga Power', 'location' => 'Naga, Cebu'],
            ['site_name' => 'Site 10 — Balamban Shipyard', 'location' => 'Balamban, Cebu'],
            ['site_name' => 'Site 11 — Talisay Housing', 'location' => 'Talisay, Cebu'],
        ];

        foreach ($sites as $site) {
            Site::updateOrCreate(['site_name' => $site['site_name']], $site + ['status' => 'active']);
        }
    }
}