<?php

namespace Database\Seeders;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * Phase 3 dev data. Crews mirror the "Crew Builder" prototype (Sites 04, 07,
 * 11); all deployed crews use effective date 2026-09-07 to keep the demo
 * deterministic. Rebar crew D stays a draft without a foreman so the "no
 * foreman → cannot deploy" rule is demonstrable from a fresh seed.
 */
class CrewSeeder extends Seeder
{
    public function run(): void
    {
        $site = static fn (string $name) => Site::where('site_name', $name)->value('site_id');
        $employ = static fn (string $code) => Employee::where('employee_code', $code)->value('employee_id');

        $crews = [
            [
                'crew_name' => 'Structural crew B',
                'site_name' => 'Site 04 — Cebu North',
                'foreman' => 'ADC-0509',       // Bacus, Elmer P.
                'members' => ['ADC-0921', 'ADC-0810'], // Cabahug, Sarmiento
                'status' => 'deployed',
            ],
            [
                'crew_name' => 'Formwork crew B',
                'site_name' => 'Site 07 — Mandaue Viaduct',
                'foreman' => 'ADC-0387',       // Dela Cruz, Ronel B.
                'members' => ['ADC-0906', 'ADC-0907'], // Malinao, Bandala
                'status' => 'deployed',
            ],
            [
                'crew_name' => 'Masonry crew G',
                'site_name' => 'Site 11 — Talisay Housing',
                'foreman' => 'ADC-0904',       // Lim, Joseph A.
                'members' => ['ADC-0905', 'ADC-0908'], // Perez, Nocete
                'status' => 'deployed',
            ],
            [
                'crew_name' => 'Rebar crew D',
                'site_name' => 'Site 07 — Mandaue Viaduct',
                'foreman' => null,             // deliberately draft: no foreman
                'members' => [],
                'status' => 'draft',
            ],
        ];

        foreach ($crews as $crewData) {
            $crew = Crew::query()->updateOrCreate(
                ['site_id' => $site($crewData['site_name']), 'crew_name' => $crewData['crew_name']],
                [
                    'foreman_id' => $crewData['foreman'] ? $employ($crewData['foreman']) : null,
                    'status' => $crewData['status'],
                    'deployed_at' => $crewData['status'] === 'deployed' ? '2026-09-07 06:00:00' : null,
                ]
            );

            foreach ($crewData['members'] as $code) {
                CrewAssignment::query()->updateOrCreate(
                    ['crew_id' => $crew->crew_id, 'employee_id' => $employ($code)],
                    ['date_assigned' => $crewData['status'] === 'deployed' ? '2026-09-07' : null, 'status' => 'active'],
                );
            }
        }
    }
}
