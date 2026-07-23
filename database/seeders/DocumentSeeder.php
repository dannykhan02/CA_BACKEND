<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $userIdByName = $this->ensureDocumentAuthors();

        $documents = [
            [
                'name' => 'Q3 Sector Performance Report.pdf',
                'type' => 'PDF', 'size_kb' => 2840, 'status' => 'Ready', 'classification' => 'Internal', 'year' => 2026,
                'created_at' => '2026-07-10 09:14:00', 'uploaded_by' => 'Amani Otieno',
                'last_updated_by' => 'Amani Otieno', 'updated_at' => '2026-07-11 15:30:00',
                'pages' => 24, 'has_structured_data' => true, 'power_bi_status' => 'synced',
                'page_flags' => [
                    ['page' => 1, 'status' => 'parsed', 'note' => 'Cover and summary parsed.'],
                    ['page' => 4, 'status' => 'partial', 'note' => 'Table partially extracted; 2 rows merged.'],
                    ['page' => 12, 'status' => 'failed', 'note' => 'Scanned image page — no text layer.'],
                ],
                'kpis' => [
                    ['label' => 'Total subscribers', 'value' => '64.2M', 'unit' => 'active', 'trend' => 'up', 'trend_value' => '+3.4%'],
                    ['label' => 'Mobile penetration', 'value' => '131.0%', 'trend' => 'up', 'trend_value' => '+1.2pp'],
                    ['label' => 'Broadband coverage', 'value' => '92.1%', 'trend' => 'up', 'trend_value' => '+0.8pp'],
                    ['label' => 'Average revenue / user', 'value' => 'KES 312', 'trend' => 'down', 'trend_value' => '-1.1%'],
                    ['label' => 'Spectrum utilisation', 'value' => '67%', 'trend' => 'flat', 'trend_value' => '0%'],
                    ['label' => 'Reported incidents', 'value' => '148', 'trend' => 'down', 'trend_value' => '-12'],
                ],
                'charts' => [
                    ['type' => 'bar', 'title' => 'Subscriber growth by operator', 'description' => 'Quarter-over-quarter net additions per licensee.',
                        'data' => [['label' => 'Safaricom', 'value' => 1.8], ['label' => 'Airtel', 'value' => 1.2], ['label' => 'Telkom', 'value' => 0.3], ['label' => 'Faiba', 'value' => 0.1]]],
                    ['type' => 'line', 'title' => 'Broadband coverage trend', 'description' => 'Monthly broadband coverage percentage over the past year.',
                        'data' => [['label' => 'Jul', 'value' => 88], ['label' => 'Sep', 'value' => 89], ['label' => 'Nov', 'value' => 90], ['label' => 'Jan', 'value' => 91], ['label' => 'Apr', 'value' => 92], ['label' => 'Jul', 'value' => 92]]],
                    ['type' => 'pie', 'title' => 'Market share by operator', 'description' => 'Share of active subscribers across licensees.',
                        'data' => [['label' => 'Safaricom', 'value' => 64], ['label' => 'Airtel', 'value' => 28], ['label' => 'Telkom', 'value' => 6], ['label' => 'Faiba', 'value' => 2]]],
                ],
                'insights' => [
                    'Mobile penetration crossed 131% driven by multi-SIM ownership and affordable data bundles.',
                    'Broadband coverage gains were concentrated in 12 counties; 8 counties remain below 70%.',
                    'Average revenue per user declined 1.1% despite subscriber growth, indicating pricing pressure.',
                    'Reported incidents dropped 12 quarter-over-quarter, largely from improved outage response times.',
                ],
            ],
            [
                'name' => 'Annual Cybersecurity Threat Assessment.docx',
                'type' => 'DOCX', 'size_kb' => 1520, 'status' => 'Needs Review', 'classification' => 'Confidential', 'year' => 2026,
                'created_at' => '2026-07-08 11:02:00', 'uploaded_by' => 'Wanjiru Kamau',
                'last_updated_by' => 'Wanjiru Kamau', 'updated_at' => '2026-07-09 10:00:00',
                'pages' => 18, 'has_structured_data' => true, 'power_bi_status' => 'not-synced',
                'page_flags' => [
                    ['page' => 3, 'status' => 'partial', 'note' => 'Embedded chart not recognised as structured data.'],
                    ['page' => 7, 'status' => 'parsed', 'note' => 'Threat taxonomy table parsed.'],
                ],
                'kpis' => [
                    ['label' => 'Reported phishing attempts', 'value' => '12,430', 'trend' => 'up', 'trend_value' => '+18%'],
                    ['label' => 'Critical vulnerabilities', 'value' => '37', 'trend' => 'down', 'trend_value' => '-9'],
                    ['label' => 'Mean time to remediate', 'value' => '4.2 days', 'trend' => 'down', 'trend_value' => '-0.6d'],
                ],
                'charts' => [
                    ['type' => 'bar', 'title' => 'Incidents by category', 'description' => 'Count of incidents grouped by threat category.',
                        'data' => [['label' => 'Phishing', 'value' => 12430], ['label' => 'Malware', 'value' => 3120], ['label' => 'DDoS', 'value' => 840], ['label' => 'Insider', 'value' => 96]]],
                ],
                'insights' => [
                    'Phishing attempts rose 18% year-over-year, with financial services the most targeted sector.',
                    'Critical vulnerabilities fell but remediation lag remains in legacy infrastructure.',
                    'Insider incidents remain low but under-reported; recommend a confidential reporting channel.',
                ],
            ],
            [
                'name' => 'Stakeholder Consultation Summary.pdf',
                'type' => 'PDF', 'size_kb' => 880, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026,
                'created_at' => '2026-07-05 14:45:00', 'uploaded_by' => 'Amani Otieno',
                'last_updated_by' => null, 'updated_at' => '2026-07-05 14:45:00',
                'pages' => 9, 'has_structured_data' => false, 'power_bi_status' => 'not-synced',
                'page_flags' => [], 'kpis' => [], 'charts' => [],
                'insights' => [
                    'Consultation focused on consumer protection and quality of service complaints.',
                    'Participants broadly supported the proposed number-portability framework.',
                    'Small operators raised concerns about infrastructure sharing costs.',
                ],
            ],
            [
                'name' => 'Spectrum Auction Outcomes 2026.pdf',
                'type' => 'PDF', 'size_kb' => 4120, 'status' => 'Processing', 'classification' => 'Restricted', 'year' => 2026,
                'created_at' => '2026-07-16 08:30:00', 'uploaded_by' => 'Amani Otieno',
                'last_updated_by' => null, 'updated_at' => '2026-07-16 08:30:00',
                'pages' => 31, 'has_structured_data' => false, 'power_bi_status' => 'not-synced',
                'progress' => 42,
                'page_flags' => [], 'kpis' => [], 'charts' => [], 'insights' => [],
            ],
            [
                'name' => 'Corrupted_scan.pdf',
                'type' => 'PDF', 'size_kb' => 2048, 'status' => 'Failed', 'classification' => 'Internal', 'year' => 2026,
                'created_at' => '2026-07-14 16:10:00', 'uploaded_by' => 'Wanjiru Kamau',
                'last_updated_by' => null, 'updated_at' => '2026-07-14 16:10:00',
                'pages' => 0, 'has_structured_data' => false, 'power_bi_status' => 'not-synced',
                'error_message' => 'The PDF appears to be password-protected. Please remove protection and re-upload.',
                'page_flags' => [], 'kpis' => [], 'charts' => [], 'insights' => [],
            ],
            [
                'name' => 'Universal Service Fund Annual Report.pdf',
                'type' => 'PDF', 'size_kb' => 3260, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2025,
                'created_at' => '2025-12-20 10:00:00', 'uploaded_by' => 'Amara Hassan',
                'last_updated_by' => null, 'updated_at' => '2025-12-20 10:00:00',
                'pages' => 42, 'has_structured_data' => true, 'power_bi_status' => 'synced',
                'page_flags' => [],
                'kpis' => [
                    ['label' => 'Fund disbursement', 'value' => 'KES 2.4B', 'trend' => 'up', 'trend_value' => '+8%'],
                    ['label' => 'Projects completed', 'value' => '187', 'trend' => 'up', 'trend_value' => '+24'],
                    ['label' => 'Beneficiaries', 'value' => '1.2M', 'trend' => 'up', 'trend_value' => '+15%'],
                ],
                'charts' => [
                    ['type' => 'bar', 'title' => 'Disbursement by project type', 'description' => 'Allocation of funds across project categories.',
                        'data' => [['label' => 'Schools', 'value' => 820], ['label' => 'Health', 'value' => 540], ['label' => 'Connectivity', 'value' => 640], ['label' => 'Training', 'value' => 220]]],
                ],
                'insights' => [
                    'School connectivity projects accounted for the largest share of disbursement.',
                    'Health facility connectivity saw the fastest year-over-year growth.',
                    'Training programs reached 1.2M beneficiaries across 38 counties.',
                ],
            ],
        ];

        foreach ($documents as $doc) {
            $document = new Document([
                'name' => $doc['name'],
                'type' => $doc['type'],
                'size_kb' => $doc['size_kb'],
                'status' => $doc['status'],
                'classification' => $doc['classification'],
                'year' => $doc['year'],
                'uploaded_by' => $userIdByName[$doc['uploaded_by']],
                'last_updated_by' => $doc['last_updated_by'] ? $userIdByName[$doc['last_updated_by']] : null,
                'pages' => $doc['pages'],
                'has_structured_data' => $doc['has_structured_data'],
                'power_bi_status' => $doc['power_bi_status'],
                'progress' => $doc['progress'] ?? null,
                'error_message' => $doc['error_message'] ?? null,
                'insights' => $doc['insights'],
            ]);
            $document->id = (string) Str::uuid();

            $document->timestamps = false;
            $document->created_at = $doc['created_at'];
            $document->updated_at = $doc['updated_at'];
            $document->save();
            $document->timestamps = true;

            foreach ($doc['page_flags'] as $flag) {
                $document->pageFlags()->create($flag);
            }
            foreach ($doc['kpis'] as $kpi) {
                $document->kpis()->create($kpi);
            }
            foreach ($doc['charts'] as $chart) {
                $document->charts()->create($chart);
            }
        }
    }

    /**
     * The seeded document rows reference three specific authors by name
     * (Amani Otieno, Wanjiru Kamau, Amara Hassan) so the "uploaded by" /
     * "last updated by" columns render meaningfully in the frontend
     * instead of blank. These are fixture accounts only — real users
     * created through the live signup flow are untouched by this.
     *
     * updateOrCreate on email means re-running this seeder is safe and
     * idempotent, but it also means if someone ever signs up through the
     * real UI using one of these exact @ca.go.ke addresses, this seeder
     * would overwrite that account's password/role on next run. Low risk
     * since these are internal demo addresses, but worth knowing.
     */
    private function ensureDocumentAuthors(): \Illuminate\Support\Collection
    {
        $authors = [
            ['email' => 'amani.otieno@ca.go.ke', 'full_name' => 'Amani Otieno', 'role' => 'Reviewer'],
            ['email' => 'wanjiru.kamau@ca.go.ke', 'full_name' => 'Wanjiru Kamau', 'role' => 'Analyst'],
            ['email' => 'amara.hassan@ca.go.ke', 'full_name' => 'Amara Hassan', 'role' => 'Administrator'],
        ];

        foreach ($authors as $a) {
            User::updateOrCreate(
                ['email' => $a['email']],
                [
                    'full_name' => $a['full_name'],
                    'password' => Hash::make(Str::random(32)), // random, unusable password — these accounts exist only as FK targets for seeded documents, not for login
                    'role' => $a['role'],
                    'active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }

        return User::whereIn('email', array_column($authors, 'email'))
            ->pluck('id', 'full_name');
    }
}