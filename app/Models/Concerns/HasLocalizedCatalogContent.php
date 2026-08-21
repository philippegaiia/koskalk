<?php

namespace App\Models\Concerns;

trait HasLocalizedCatalogContent
{
    public function localizedName(?string $locale = null): string
    {
        return $this->localizedCatalogValue('name', $locale) ?? (string) $this->name;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        return $this->localizedCatalogValue('description', $locale) ?? $this->description;
    }

    public function localizedShortName(?string $locale = null): ?string
    {
        return $this->localizedCatalogValue('short_name', $locale) ?? $this->short_name;
    }

    private function localizedCatalogValue(string $attribute, ?string $locale): ?string
    {
        $localizedValue = data_get($this->translations, ($locale ?? app()->getLocale()).'.'.$attribute);

        return is_string($localizedValue) && trim($localizedValue) !== '' ? $localizedValue : null;
    }
}
