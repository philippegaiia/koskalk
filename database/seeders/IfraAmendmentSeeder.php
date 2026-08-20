<?php

namespace Database\Seeders;

use App\Enums\IfraAmendmentStatus;
use App\Models\IfraAmendment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IfraAmendmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $amendment = IfraAmendment::query()->updateOrCreate(
                ['code' => '51'],
                [
                    'status' => IfraAmendmentStatus::Notified,
                    'notification_date' => '2023-06-30',
                    'source_url' => 'https://ifrafragrance.org/docs/default-source/51st-amendment/ifra-51st-amendment---notification-letter.pdf?sfvrsn=aa518b6a_2',
                    'notes' => 'Current formally notified IFRA amendment.',
                ],
            );

            foreach ($this->milestones() as $milestone) {
                $amendment->milestones()->updateOrCreate(
                    [
                        'standard_kind' => $milestone['standard_kind'],
                        'creation_track' => $milestone['creation_track'],
                    ],
                    ['effective_on' => $milestone['effective_on']],
                );
            }
        }, attempts: 5);
    }

    /**
     * @return array<int, array{standard_kind:string, creation_track:string, effective_on:string}>
     */
    private function milestones(): array
    {
        return [
            ['standard_kind' => 'prohibition', 'creation_track' => 'new', 'effective_on' => '2023-08-30'],
            ['standard_kind' => 'prohibition', 'creation_track' => 'existing', 'effective_on' => '2024-07-30'],
            ['standard_kind' => 'restriction_specification', 'creation_track' => 'new', 'effective_on' => '2024-03-30'],
            ['standard_kind' => 'restriction_specification', 'creation_track' => 'existing', 'effective_on' => '2025-10-30'],
        ];
    }
}
