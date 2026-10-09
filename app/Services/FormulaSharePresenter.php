<?php

namespace App\Services;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\Workspace;
use Illuminate\Support\Arr;

class FormulaSharePresenter
{
    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function outgoing(array $snapshot): array
    {
        return [
            'product_name' => $snapshot['product']['name'],
            'settings' => $this->settings($snapshot),
            'warnings' => array_values(array_intersect($snapshot['warnings'] ?? [], ['procedure_media_excluded'])),
            'ifra' => ['category_code' => data_get($snapshot, 'formula.ifra.category.code'), 'amendment_code' => data_get($snapshot, 'formula.ifra.amendment.code'), 'changed' => false],
            'description' => $snapshot['product']['description'] ?? null,
            'procedure' => $snapshot['formula']['manufacturing_instructions'] ?? null,
            'phases' => $snapshot['formula']['phases'],
            'ingredients' => collect($snapshot['ingredients']['nodes'])->map(function (array $node, string $key) use ($snapshot): array {
                return ['key' => $key, 'kind' => $node['kind'], 'name' => $node['display']['display_name'], 'identity' => $node['display'], 'technical' => $this->technical($node['technical'], $snapshot['ingredients']['nodes'])];
            })->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot @return array<string, string|int|float> */
    public function settings(array $snapshot): array
    {
        $formula = $snapshot['formula'];
        $context = $formula['calculation_context'] ?? [];
        $settings = Arr::only($formula, ['batch_size', 'batch_unit', 'manufacturing_mode', 'exposure_mode']);
        $settings += Arr::only($context, ['editing_mode', 'oil_weight', 'oil_unit', 'lye_type', 'koh_purity_percentage', 'dual_lye_koh_percentage', 'superfat']);
        $settings += [
            'product_family' => data_get($snapshot, 'product.family.slug'),
            'product_type' => data_get($snapshot, 'product.type.slug'),
            'calculation_basis' => data_get($snapshot, 'product.family.calculation_basis'),
            'regulatory_regime' => data_get($formula, 'regulatory_regime.code'),
            'dilution_mode' => data_get($formula, 'water_settings.mode'),
            'dilution_value' => data_get($formula, 'water_settings.value'),
            'ifra_selection_mode' => data_get($formula, 'ifra.selection_mode'),
        ];

        return array_filter($settings, fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value));
    }

    /** @return array<string, mixed> */
    public function summary(FormulaShare $share, Workspace $workspace): array
    {
        $incoming = $share->recipient_workspace_id === $workspace->id;
        $status = $share->status === FormulaShareStatus::Pending && ! $share->isPending() ? FormulaShareStatus::Expired : $share->status;
        $accepted = $incoming ? $share->acceptedRecipe : null;

        return [
            'public_id' => $share->public_id,
            'incoming' => $incoming,
            'sender' => $share->sender_workspace_name,
            'recipient' => $share->recipientWorkspace?->name ?? __('sharing.removed_workspace'),
            'product_name' => data_get($share->snapshot, 'product.name') ?? __('sharing.removed_product'),
            'status' => $status->value,
            'expires_at' => $share->expires_at->toDateTimeString(),
            'accepted_product_public_id' => $accepted?->workspace_id === $workspace->id ? $accepted->public_id : null,
            'pending' => $share->isPending(),
        ];
    }

    /** @param array<string, mixed> $technical @return array<string, mixed> */
    public function technical(array $technical, array $nodes): array
    {
        unset($technical['baseline'], $technical['is_soap_saponification_trusted']);
        $technical['components'] = collect($technical['components'])->map(fn (array $row): array => [
            'name' => $nodes[$row['key']]['display']['display_name'] ?? __('sharing.ingredient'),
            'percentage_in_parent' => $row['percentage_in_parent'],
        ])->all();
        $strip = function (mixed $value) use (&$strip): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['id']);

            return array_map($strip, $value);
        };

        return $strip($technical);
    }
}
