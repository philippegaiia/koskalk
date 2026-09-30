<?php

namespace App\Console\Commands;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('formula-shares:prune')]
#[Description('Expire pending Formula offers and purge closed payloads after their retention period')]
class PruneFormulaShares extends Command
{
    public function handle(): int
    {
        $now = now()->toImmutable();
        $cutoff = $now->subDays(30);
        $expired = 0;
        $purged = 0;
        FormulaShare::query()->whereNull('payload_purged_at')
            ->where(function (Builder $query) use ($now, $cutoff): void {
                $query->where(fn (Builder $pending): Builder => $pending->where('status', FormulaShareStatus::Pending)->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $closed): Builder => $closed->whereIn('status', [FormulaShareStatus::Declined, FormulaShareStatus::Revoked, FormulaShareStatus::Expired])->where('closed_at', '<=', $cutoff))
                    ->orWhere(fn (Builder $accepted): Builder => $accepted->where('status', FormulaShareStatus::Accepted)->whereNull('accepted_recipe_id')->where('accepted_product_deleted_at', '<=', $cutoff));
            })->select('id')->chunkById(100, function (Collection $shares) use ($now, $cutoff, &$expired, &$purged): void {
                foreach ($shares as $candidate) {
                    DB::transaction(function () use ($candidate, $now, $cutoff, &$expired, &$purged): void {
                        $share = FormulaShare::query()->lockForUpdate()->find($candidate->id);
                        if ($share === null || $share->payload_purged_at !== null) {
                            return;
                        }
                        if ($share->status === FormulaShareStatus::Pending && $share->expires_at->lte($now)) {
                            $share->forceFill(['status' => FormulaShareStatus::Expired, 'closed_at' => $share->expires_at]);
                            $expired++;
                        }
                        $retainedUntil = match ($share->status) {
                            FormulaShareStatus::Declined, FormulaShareStatus::Revoked, FormulaShareStatus::Expired => $share->closed_at,
                            FormulaShareStatus::Accepted => $share->accepted_recipe_id === null ? $share->accepted_product_deleted_at : null,
                            default => null,
                        };
                        if ($retainedUntil !== null && $retainedUntil->lte($cutoff)) {
                            $share->forceFill(['snapshot' => null, 'options' => null, 'import_receipt' => null, 'payload_purged_at' => $now]);
                            $purged++;
                        }
                        if ($share->isDirty()) {
                            $share->save();
                        }
                    }, attempts: 5);
                }
            });
        $this->info("{$expired} offers expired; {$purged} payloads purged.");

        return self::SUCCESS;
    }
}
