<?php

namespace Database\Seeders;

use App\Models\InterfaceTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InterfaceTranslationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->translations() as $fullKey => $translations) {
            $line = InterfaceTranslation::query()->firstOrNew([
                'group' => Str::before($fullKey, '.'),
                'key' => Str::after($fullKey, '.'),
            ]);

            $line->text = array_replace($translations, $line->text ?? []);
            $line->save();
        }
    }

    /**
     * @return array<string, array{fr: string, es: string, de: string, it: string, nl: string}>
     */
    private function translations(): array
    {
        return [
            'navigation.actions.sign_out' => ['fr' => 'Se déconnecter', 'es' => 'Cerrar sesión', 'de' => 'Abmelden', 'it' => 'Esci', 'nl' => 'Uitloggen'],
            'navigation.items.account' => ['fr' => 'Compte', 'es' => 'Cuenta', 'de' => 'Konto', 'it' => 'Account', 'nl' => 'Account'],
            'navigation.items.compliance' => ['fr' => 'Conformité', 'es' => 'Cumplimiento', 'de' => 'Konformität', 'it' => 'Conformità', 'nl' => 'Regelgeving'],
            'navigation.items.formulas' => ['fr' => 'Produits', 'es' => 'Productos', 'de' => 'Produkte', 'it' => 'Prodotti', 'nl' => 'Producten'],
            'navigation.items.home' => ['fr' => 'Accueil', 'es' => 'Inicio', 'de' => 'Startseite', 'it' => 'Home', 'nl' => 'Start'],
            'navigation.items.ingredients' => ['fr' => 'Ingrédients', 'es' => 'Ingredientes', 'de' => 'Inhaltsstoffe', 'it' => 'Ingredienti', 'nl' => 'Ingrediënten'],
            'navigation.items.overview' => ['fr' => 'Aperçu', 'es' => 'Resumen', 'de' => 'Übersicht', 'it' => 'Panoramica', 'nl' => 'Overzicht'],
            'navigation.items.packaging' => ['fr' => 'Emballages', 'es' => 'Envases', 'de' => 'Verpackungen', 'it' => 'Imballaggi', 'nl' => 'Verpakkingen'],
            'navigation.items.settings' => ['fr' => 'Paramètres', 'es' => 'Ajustes', 'de' => 'Einstellungen', 'it' => 'Impostazioni', 'nl' => 'Instellingen'],
            'navigation.menu.close' => ['fr' => 'Fermer le menu', 'es' => 'Cerrar el menú', 'de' => 'Menü schließen', 'it' => 'Chiudi il menu', 'nl' => 'Menu sluiten'],
            'navigation.menu.collapse' => ['fr' => 'Réduire le menu', 'es' => 'Contraer el menú', 'de' => 'Menü einklappen', 'it' => 'Riduci il menu', 'nl' => 'Menu inklappen'],
            'navigation.menu.toggle' => ['fr' => 'Afficher ou masquer le menu', 'es' => 'Mostrar u ocultar el menú', 'de' => 'Menü ein- oder ausblenden', 'it' => 'Mostra o nascondi il menu', 'nl' => 'Menu tonen of verbergen'],
            'navigation.status.coming_soon' => ['fr' => 'Bientôt disponible', 'es' => 'Próximamente', 'de' => 'Demnächst verfügbar', 'it' => 'Prossimamente', 'nl' => 'Binnenkort beschikbaar'],
            'navigation.user.aria_label' => ['fr' => 'Utilisateur connecté', 'es' => 'Usuario conectado', 'de' => 'Angemeldeter Benutzer', 'it' => 'Utente connesso', 'nl' => 'Ingelogde gebruiker'],
            'navigation.user.free_account' => ['fr' => 'Compte gratuit', 'es' => 'Cuenta gratuita', 'de' => 'Kostenloses Konto', 'it' => 'Account gratuito', 'nl' => 'Gratis account'],
            'navigation.user.signed_in' => ['fr' => 'Connecté', 'es' => 'Sesión iniciada', 'de' => 'Angemeldet', 'it' => 'Accesso effettuato', 'nl' => 'Ingelogd'],
            'dashboard.title' => ['fr' => 'Aperçu', 'es' => 'Resumen', 'de' => 'Übersicht', 'it' => 'Panoramica', 'nl' => 'Overzicht'],
            'dashboard.create.heading' => ['fr' => 'Créer un produit', 'es' => 'Crear un producto', 'de' => 'Produkt erstellen', 'it' => 'Crea un prodotto', 'nl' => 'Product maken'],
            'dashboard.create.soap' => ['fr' => 'Nouveau savon', 'es' => 'Nuevo jabón', 'de' => 'Neue Seife', 'it' => 'Nuovo sapone', 'nl' => 'Nieuwe zeep'],
            'dashboard.create.cosmetic' => ['fr' => 'Nouveau produit cosmétique', 'es' => 'Nuevo producto cosmético', 'de' => 'Neues Kosmetikprodukt', 'it' => 'Nuovo prodotto cosmetico', 'nl' => 'Nieuw cosmeticaproduct'],
            'dashboard.library.heading' => ['fr' => 'Vos produits', 'es' => 'Tus productos', 'de' => 'Ihre Produkte', 'it' => 'I tuoi prodotti', 'nl' => 'Je producten'],
            'dashboard.library.products' => ['fr' => 'Produits', 'es' => 'Productos', 'de' => 'Produkte', 'it' => 'Prodotti', 'nl' => 'Producten'],
            'dashboard.library.ingredients' => ['fr' => 'Ingrédients', 'es' => 'Ingredientes', 'de' => 'Inhaltsstoffe', 'it' => 'Ingredienti', 'nl' => 'Ingrediënten'],
            'dashboard.library.locked_products' => ['fr' => 'Produits verrouillés', 'es' => 'Productos bloqueados', 'de' => 'Gesperrte Produkte', 'it' => 'Prodotti bloccati', 'nl' => 'Vergrendelde producten'],
        ];
    }
}
