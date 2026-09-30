<?php

namespace App\Models;

use App\Enums\FormulaShareStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\FormulaShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['status'])]
#[Visible(['public_id', 'status', 'sender_workspace_name', 'sent_at', 'expires_at', 'accepted_at', 'closed_at'])]
class FormulaShare extends Model
{
    /** @use HasFactory<FormulaShareFactory> */
    use HasFactory;

    use HasPublicId;

    public function sourceWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'source_workspace_id')->withoutGlobalScopes();
    }

    public function recipientWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'recipient_workspace_id')->withoutGlobalScopes();
    }

    public function sourceRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'source_recipe_id')->withoutGlobalScopes();
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'source_version_id')->withoutGlobalScopes();
    }

    public function acceptedRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'accepted_recipe_id')->withoutGlobalScopes();
    }

    public function isPending(): bool
    {
        return $this->status === FormulaShareStatus::Pending && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'status' => FormulaShareStatus::class,
            'schema_version' => 'integer',
            'snapshot' => 'array',
            'options' => 'array',
            'import_receipt' => 'array',
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'payload_purged_at' => 'immutable_datetime',
            'accepted_product_deleted_at' => 'immutable_datetime',
        ];
    }
}
