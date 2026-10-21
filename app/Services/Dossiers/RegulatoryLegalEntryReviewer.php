<?php

namespace App\Services\Dossiers;

use App\Enums\LegalEntryProductScope;
use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegulatoryLegalEntryReviewer
{
    public function markReviewed(User $admin, RegulatoryLegalEntry $entry): RegulatoryLegalEntry
    {
        if (! $admin->is_admin) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($admin, $entry): RegulatoryLegalEntry {
            $locked = RegulatoryLegalEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isReviewed()) {
                return $locked;
            }

            $this->assertComplete($locked);

            $locked->update([
                'review_status' => LegalEntryReviewStatus::Reviewed,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked;
        }, attempts: 5);
    }

    private function assertComplete(RegulatoryLegalEntry $entry): void
    {
        $errors = [];

        if (blank($entry->source_reference)) {
            $errors['source_reference'] = 'Cite the consolidated legal text the entry was transcribed from.';
        }

        if (! $entry->substances()->exists()) {
            $errors['substances'] = 'Link at least one substance.';
        }

        if (preg_match('/^'.preg_quote($entry->annex->value, '/').'\/\d+[a-z]?$/', $entry->entry_reference) !== 1) {
            $errors['entry_reference'] = "Use the form {$entry->annex->value}/<number>, matching the annex.";
        }

        if ($entry->product_scope === LegalEntryProductScope::SeeConditions && blank($entry->product_type_body_parts)) {
            $errors['product_type_body_parts'] = 'Transcribe the product type and body parts the conditions refer to.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
