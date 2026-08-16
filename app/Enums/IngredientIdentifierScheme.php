<?php

namespace App\Enums;

enum IngredientIdentifierScheme: string
{
    case Cas = 'cas';
    case Ec = 'ec';
    case Unii = 'unii';
    case EchaList = 'echa_list';
    case InchiKey = 'inchikey';
    case PubchemCid = 'pubchem_cid';
    case PubchemSid = 'pubchem_sid';
    case CosingRef = 'cosing_ref';

    public function label(): string
    {
        return match ($this) {
            self::Cas => __('ingredients.editor.identity.identifier_schemes.cas'),
            self::Ec => __('ingredients.editor.identity.identifier_schemes.ec'),
            self::Unii => __('ingredients.editor.identity.identifier_schemes.unii'),
            self::EchaList => __('ingredients.editor.identity.identifier_schemes.echa_list'),
            self::InchiKey => __('ingredients.editor.identity.identifier_schemes.inchikey'),
            self::PubchemCid => __('ingredients.editor.identity.identifier_schemes.pubchem_cid'),
            self::PubchemSid => __('ingredients.editor.identity.identifier_schemes.pubchem_sid'),
            self::CosingRef => __('ingredients.editor.identity.identifier_schemes.cosing_ref'),
        };
    }
}
