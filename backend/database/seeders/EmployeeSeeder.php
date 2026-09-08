<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Dev seed data. The login-capable accounts all use the password "password"
     * (documented in README as dev-only — do not reuse in production).
     * Sample field workers reuse names from the design prototypes.
     */
    public function run(): void
    {
        $role = static fn (string $slug) => Role::where('slug', $slug)->value('role_id');
        $site = static fn (string $name) => Site::where('site_name', $name)->value('site_id');

        $accounts = [
            [
                'employee_code' => 'ADC-0001',
                'email' => 'f.uy@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('admin'),
                'site_id' => $site('Site 01 — Mactan Industrial'),
                'first_name' => 'Francis',
                'last_name' => 'Uy',
                'middle_name' => 'Lim',
                'employment_status' => 'regular',
                'date_hired' => '2016-03-01',
                'cost_centre' => 'CO-00',
            ],
            [
                'employee_code' => 'ADC-0002',
                'email' => 'mreyes@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('hr'),
                'site_id' => $site('Site 01 — Mactan Industrial'),
                'first_name' => 'Marilou',
                'last_name' => 'Reyes',
                'middle_name' => 'Aguilar',
                'employment_status' => 'regular',
                'date_hired' => '2017-08-14',
                'cost_centre' => 'CO-00',
            ],
            [
                'employee_code' => 'ADC-0455',
                'email' => 'jomar.abainza@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('engineer'),
                'site_id' => $site('Site 04 — Cebu North'),
                'first_name' => 'Jomar',
                'last_name' => 'Abainza',
                'middle_name' => 'Teodoro',
                'trade_skill' => 'Steelwork',
                'employment_status' => 'regular',
                'date_hired' => '2022-06-19',
                'cost_centre' => 'CO-04',
            ],
            [
                'employee_code' => 'ADC-0509',
                'email' => 'elmer.bacus@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('foreman'),
                'site_id' => $site('Site 04 — Cebu North'),
                'first_name' => 'Elmer',
                'last_name' => 'Bacus',
                'middle_name' => 'Pantaleon',
                'trade_skill' => 'Formwork',
                'employment_status' => 'regular',
                'date_of_birth' => '1984-07-14',
                'mobile' => '+63 917 428 1160',
                'civil_status' => 'Married',
                'dependents' => 3,
                'address' => 'Brgy. Tayud, Consolacion, Cebu',
                'blood_type' => 'O+',
                'tin' => '284-119-045',
                'sss' => '33-8812445-1',
                'philhealth' => '12-104778812-4',
                'pag_ibig' => '1210-4477-8891',
                'date_hired' => '2019-02-04',
                'cost_centre' => 'CO-04',
            ],
            [
                'employee_code' => 'ADC-0387',
                'email' => 'ronel.delacruz@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('foreman'),
                'site_id' => $site('Site 07 — Mandaue Viaduct'),
                'first_name' => 'Ronel',
                'last_name' => 'Dela Cruz',
                'middle_name' => 'Buenaventura',
                'trade_skill' => 'Formwork',
                'employment_status' => 'regular',
                'date_hired' => '2018-11-05',
                'cost_centre' => 'CO-07',
            ],
            [
                'employee_code' => 'ADC-0007',
                'email' => 'ma.arcenas@arcenasdev.ph',
                'password' => 'password',
                'role_id' => $role('executive'),
                'site_id' => $site('Site 01 — Mactan Industrial'),
                'first_name' => 'Ma. Teresa',
                'last_name' => 'Arcenas',
                'employment_status' => 'regular',
                'date_hired' => '2009-01-12',
                'cost_centre' => 'CO-00',
            ],
        ];

        foreach ($accounts as $account) {
            Employee::query()->updateOrCreate(['employee_code' => $account['employee_code']], $account);
        }

        $workers = [
            ['ADC-0742', 'Kevin', 'Ompad', 'Rafael', 'worker', 'Rebar', 'Site 04 — Cebu North', '2024-03-03', 'probationary'],
            ['ADC-0810', 'Noel', 'Sarmiento', 'Dinglasan', 'worker', 'Masonry', 'Site 04 — Cebu North', '2023-11-11', 'project_based'],
            ['ADC-0388', 'Rico', 'Gantuangco', 'Araneta', 'operator', 'Heavy equipment', 'Site 04 — Cebu North', '2017-08-22', 'regular'],
            ['ADC-0921', 'Lito', 'Cabahug', 'Mercader', 'worker', 'Welding', 'Site 04 — Cebu North', '2025-01-07', 'probationary'],
            ['ADC-0104', 'Wenceslao', 'Paras', 'Baguio', 'worker', 'Formwork', 'Site 04 — Cebu North', '2014-05-19', 'separated'],
            ['ADC-0611', 'Anna Lyn', 'Villacruz', 'Sales', 'hr', null, 'Site 01 — Mactan Industrial', '2021-06-30', 'regular'],
            ['ADC-0904', 'Joseph', 'Lim', 'Antonio', 'foreman', 'Formwork', 'Site 11 — Talisay Housing', '2026-09-08', 'probationary'],
            ['ADC-0177', 'Divina', 'Cortes', 'Manese', 'hr', null, 'Site 01 — Mactan Industrial', '2015-02-23', 'regular'],
            ['ADC-0905', 'Emmanuel', 'Perez', 'Go', 'worker', 'Masonry', 'Site 11 — Talisay Housing', '2026-01-15', 'seasonal'],
            ['ADC-0906', 'Rhoda', 'Malinao', 'Suyo', 'worker', 'Rebar', 'Site 07 — Mandaue Viaduct', '2025-09-15', 'probationary'],
            ['ADC-0907', 'Greggy', 'Bandala', 'Rosquillos', 'worker', 'Steelwork', 'Site 07 — Mandaue Viaduct', '2026-02-10', 'seasonal'],
            ['ADC-0908', 'Cherry', 'Nocete', 'Patalinghug', 'worker', 'Masonry', 'Site 11 — Talisay Housing', '2024-10-21', 'regular'],
        ];

        foreach ($workers as [$code, $first, $last, $middle, $roleSlug, $trade, $siteName, $hired, $status]) {
            Employee::query()->updateOrCreate(
                ['employee_code' => $code],
                [
                    'role_id' => $role($roleSlug),
                    'site_id' => $site($siteName),
                    'first_name' => $first,
                    'last_name' => $last,
                    'middle_name' => $middle,
                    'trade_skill' => $trade,
                    'employment_status' => $status,
                    'date_hired' => $hired,
                ]
            );
        }

        // Certification entries exercise the Crew Builder pool's cert-status
        // guardrails (valid / expiring soon / expired → unselectable).
        // Shape matches the web form's emptyCert(): name/issuer/certificate_no/issued_at/expires_at.
        $certs = [
            'ADC-0810' => [
                [
                    'name' => 'BCWS — Basic Construction Safety',
                    'issuer' => 'DOLE Accredited Training Org',
                    'certificate_no' => 'BCWS-2024-0182',
                    'issued_at' => '2024-06-15',
                    'expires_at' => '2027-06-15',
                ],
            ],
            'ADC-0388' => [
                [
                    'name' => 'Heavy Equipment Operator License',
                    'issuer' => 'TESDA',
                    'certificate_no' => 'NCII-2204-1147',
                    'issued_at' => '2024-05-01',
                    'expires_at' => '2026-05-01',
                ],
                [
                    'name' => 'Forklift Operation Safety',
                    'issuer' => 'Company In-house',
                    'certificate_no' => 'FLT-2023-0902',
                    'issued_at' => '2023-11-30',
                    'expires_at' => '2025-11-30',
                ],
            ],
            'ADC-0906' => [
                [
                    'name' => 'Scaffold Erection & Inspection',
                    'issuer' => 'DOLE Accredited Training Org',
                    'certificate_no' => 'SCF-2025-0410',
                    'issued_at' => '2025-09-18',
                    'expires_at' => '2026-09-18',
                ],
            ],
        ];

        foreach ($certs as $code => $certifications) {
            Employee::query()->where('employee_code', $code)->update(['certification' => $certifications]);
        }
    }
}
