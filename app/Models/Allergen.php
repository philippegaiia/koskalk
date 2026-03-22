<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_name',
    'source_file',
    'inci_name',
    'cas_number',
    'ec_number',
    'common_name_en',
    'common_name_fr',
    'source_data',
])]
class Allergen extends Model
{
    protected $table = 'allergen_catalog';

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
        ];
    }
}
