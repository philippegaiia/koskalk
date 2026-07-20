<?php

namespace Database\Seeders;

use App\Models\SupportedLocale;
use Illuminate\Database\Seeder;

class SupportedLocaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SupportedLocale::query()->upsert([
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'number_locale' => 'en_US',
                'text_direction' => 'ltr',
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'fr',
                'name' => 'French',
                'native_name' => 'Français',
                'number_locale' => 'fr_FR',
                'text_direction' => 'ltr',
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 20,
            ],
            [
                'code' => 'es',
                'name' => 'Spanish',
                'native_name' => 'Español',
                'number_locale' => 'es_ES',
                'text_direction' => 'ltr',
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 30,
            ],
            [
                'code' => 'de',
                'name' => 'German',
                'native_name' => 'Deutsch',
                'number_locale' => 'de_DE',
                'text_direction' => 'ltr',
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 40,
            ],
            [
                'code' => 'it',
                'name' => 'Italian',
                'native_name' => 'Italiano',
                'number_locale' => 'it_IT',
                'text_direction' => 'ltr',
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 50,
            ],
            [
                'code' => 'nl',
                'name' => 'Dutch',
                'native_name' => 'Nederlands',
                'number_locale' => 'nl_NL',
                'text_direction' => 'ltr',
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 60,
            ],
        ], ['code'], [
            'name',
            'native_name',
            'number_locale',
            'text_direction',
            'sort_order',
        ]);
    }
}
