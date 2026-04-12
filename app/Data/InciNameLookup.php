<?php

declare(strict_types=1);

namespace App\Data;

final class InciNameLookup
{
    private const Map = [
        'Coconut Oil' => 'Cocos Nucifera',
        'Olive Oil' => 'Olea Europaea',
        'Palm Oil' => 'Elaeis Guineensis',
        'Castor Oil' => 'Ricinus Communis',
        'Sunflower Oil' => 'Helianthus Annuus',
        'Sweet Almond Oil' => 'Prunus Amygdalus Dulcis',
        'Apricot Kernel Oil' => 'Prunus Armeniaca',
        'Avocado Oil' => 'Persea Gratissima',
        'Babassu Oil' => 'Orbignya Oleifera',
        'Black Cumin Oil' => 'Nigella Sativa',
        'Borage Oil' => 'Borago Officinalis',
        'Broccoli Seed Oil' => 'Brassica Oleracea Italica',
        'Camelina Oil' => 'Camelina Sativa',
        'Camellia Oil' => 'Camellia Japonica',
        'Canola Oil' => 'Brassica Campestris',
        'Carrot Seed Oil' => 'Daucus Carota Sativa',
        'Cherry Kernel Oil' => 'Prunus Avium',
        'Chia Seed Oil' => 'Salvia Hispanica',
        'Cocoa Butter' => 'Theobroma Cacao',
        'Corn Oil' => 'Zea Mays',
        'Flaxseed Oil' => 'Linum Usitatissimum',
        'Grape Seed Oil' => 'Vitis Vinifera',
        'Hazelnut Oil' => 'Corylus Avellana',
        'Hempseed Oil' => 'Cannabis Sativa',
        'Jojoba Oil' => 'Simmondsia Chinensis',
        'Karanja Oil' => 'Pongamia Glabra',
        'Kukui Nut Oil' => 'Aleurites Moluccana',
        'Linseed Oil' => 'Linum Usitatissimum',
        'Macadamia Oil' => 'Macadamia Integrifolia',
        'Mango Butter' => 'Mangifera Indica',
        'Meadowfoam Oil' => 'Limnanthes Alba',
        'Moringa Oil' => 'Moringa Oleifera',
        'Neem Oil' => 'Azadirachta Indica',
        'Evening Primrose Oil' => 'Oenothera Biennis',
        'Palm Kernel Oil' => 'Elaeis Guineensis',
        'Peach Kernel Oil' => 'Prunus Persica',
        'Peanut Oil' => 'Arachis Hypogaea',
        'Pistachio Oil' => 'Pistacia Vera',
        'Pomegranate Oil' => 'Punica Granatum',
        'Pumpkin Seed Oil' => 'Cucurbita Pepo',
        'Rapeseed Oil' => 'Brassica Napus',
        'Rice Bran Oil' => 'Oryza Sativa',
        'Rosehip Oil' => 'Rosa Rubiginosa',
        'Safflower Oil' => 'Carthamus Tinctorius',
        'Sal Butter' => 'Shorea Robusta',
        'Sesame Oil' => 'Sesamum Indicum',
        'Shea Butter' => 'Butyrospermum Parkii',
        'Soybean Oil' => 'Glycine Soja',
        'Tung Oil' => 'Aleurites Fordii',
        'Walnut Oil' => 'Juglans Regia',
        'Wheat Germ Oil' => 'Triticum Vulgare',
        'Chicken Fat' => null,
        'Lard' => null,
        'Tallow' => null,
        'Beeswax' => 'Cera Alba',
        'Carnauba Wax' => 'Copernicia Cerifera',
    ];

    public static function map(): array
    {
        return self::Map;
    }

    public static function find(string $commonName): ?string
    {
        return self::Map[$commonName] ?? null;
    }
}
