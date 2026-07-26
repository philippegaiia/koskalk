<?php

$translations = array_replace_recursive(require lang_path('en/media_library.php'), [
    'panel' => ['settings' => 'Réglages', 'settings_for' => 'Ouvrir les réglages de :name', 'close' => 'Fermer les détails de l’image', 'tabs_label' => 'Détails de l’image', 'search_usage' => 'Rechercher parmi ces utilisations', 'no_usages' => 'Cette image n’est pas encore utilisée.', 'no_matching_usages' => 'Aucune utilisation ne correspond à cette recherche.', 'groups' => ['recipes' => 'Recettes', 'ingredients' => 'Ingrédients', 'packaging' => 'Emballages', 'other' => 'Autres'], 'delete' => 'Supprimer', 'delete_confirm' => 'Supprimer « :name » ? Cette action est irréversible.'],
    'title' => 'Médiathèque', 'eyebrow' => 'Médias de l’espace de travail', 'description' => 'Importez une fois, puis réutilisez la même image dans les produits, ingrédients, emballages et instructions de fabrication.', 'upload' => 'Importer une image', 'uploading' => 'Importation…', 'choose_upload' => 'Choisir une image à importer', 'uploads_blocked' => 'Les nouveaux imports sont bloqués jusqu’à la suppression d’un média inutilisé ou la mise à niveau du forfait. Les médias existants restent disponibles.', 'usage' => '{0} :count utilisations|{1} :count utilisation|[2,*] :count utilisations', 'original_filename' => 'Original : :name', 'display_name' => 'Nom d’affichage', 'search_placeholder' => 'Nom ou fichier d’origine', 'search' => 'Rechercher', 'rename' => 'Renommer', 'save_name' => 'Enregistrer le nom', 'usage_details' => 'Utilisé par', 'missing_target' => 'Élément supprimé', 'detach_before_removing' => 'Détachez cette image partout avant de la supprimer.', 'quota' => ['unlimited' => ':used médias utilisés · Illimité', 'limited' => ':used médias utilisés sur :limit'], 'filters' => ['aria_label' => 'Filtres des médias', 'processing_status' => 'État du traitement', 'all' => 'Tous', 'used' => 'Utilisés', 'unused' => 'Inutilisés'], 'statuses' => ['all' => 'Tous les états', 'processing' => 'Traitement', 'ready' => 'Prêt', 'failed' => 'Échec'], 'processing_stages' => ['queued' => 'en attente', 'validating' => 'validation', 'normalizing' => 'normalisation', 'converting' => 'conversion'], 'empty' => ['title' => 'Aucun média trouvé', 'description' => 'Importez une image ou ajustez les filtres.'], 'actions' => ['retry' => 'Réessayer', 'remove' => 'Supprimer', 'remove_unused' => 'Supprimer le média inutilisé'], 'crop' => ['adjust' => 'Ajuster le recadrage carré', 'horizontal' => 'Point focal horizontal', 'vertical' => 'Point focal vertical', 'save' => 'Enregistrer le point focal'], 'picker' => ['choose' => 'Choisir dans la médiathèque', 'choose_multiple' => 'Choisir des images', 'clear' => 'Effacer', 'close' => 'Fermer le sélecteur de médias', 'library' => 'Médiathèque', 'upload_new' => 'Nouvel import', 'upload' => 'Importer', 'upload_heading' => 'Importer dans la médiathèque', 'upload_description' => 'Le sélecteur reste ouvert pendant le traitement. L’image sera sélectionnée automatiquement une fois prête.', 'image' => 'Image', 'select_one' => 'Sélectionnez une image prête.', 'select_multiple' => 'Sélectionnez jusqu’à :count images prêtes.', 'no_selection' => 'Aucune image sélectionnée.', 'search_placeholder' => 'Rechercher par nom ou fichier d’origine', 'search_label' => 'Rechercher des médias', 'empty_title' => 'Votre médiathèque est vide', 'empty_description' => 'Importez une image, puis revenez la sélectionner ici.', 'upload_unavailable' => 'Les nouveaux imports sont bloqués. Les médias prêts existants restent sélectionnables.', 'processing' => 'Traitement de l’image importée', 'failed' => 'Échec du traitement de l’image', 'refresh_failed' => 'Impossible d’actualiser l’état. Réessayez.', 'retry' => 'Réessayer', 'remove' => 'Supprimer', 'done' => 'Terminé', 'manage' => 'Gérer la médiathèque', 'processing_progress' => ':progress % traité', 'processing_failed' => 'Échec du traitement'],
    'roles' => ['recipe_featured' => 'Image principale de la recette', 'recipe_sop' => 'Instruction de recette', 'ingredient_main' => 'Image principale de l’ingrédient', 'ingredient_icon_override' => 'Icône de l’ingrédient', 'packaging_main' => 'Image principale de l’emballage'], 'messages' => ['renamed' => '« :name » a été renommé.', 'upload_processing' => '« :name » est en cours de traitement.', 'retry_processing' => '« :name » est de nouveau en cours de traitement.', 'removed' => '« :name » a été supprimé.', 'focal_refreshing' => 'Le point focal du recadrage carré est en cours d’actualisation.'], 'validation' => ['display_name_required' => 'Saisissez un nom d’affichage.', 'display_name_max' => 'Le nom d’affichage ne doit pas dépasser 255 caractères.', 'focal_point' => 'Choisissez un point focal dans l’image.'],
]);

return array_replace_recursive($translations, [
    'choose_files' => 'Choisir des images',
    'selected_files' => '{1} :count image sélectionnée|[2,*] :count images sélectionnées',
    'upload_selected' => 'Importer les images sélectionnées',
    'batch_limit' => 'Limite de :max images par import. Réduisez la sélection de :count pour continuer.',
    'batch_position' => 'Importation de :current sur :total',
    'remove_file' => 'Retirer :name',
    'batch_file_failed' => 'Impossible d’importer :name. Réessayez ou retirez le fichier.',
    'batch_quota' => 'Capacité restante de votre forfait : :count médias.',
    'picker' => ['insert_from_media_library' => 'Insérer depuis la médiathèque', 'choose_file' => 'Choisir une image', 'no_file_selected' => 'Aucune image sélectionnée', 'load_more' => 'Charger plus', 'polling_stopped' => 'Les mises à jour d’état ont été arrêtées après plusieurs erreurs de connexion. Réessayez l’import.'],
    'validation' => [
        'picker_ready_workspace' => 'Choisissez une image prête dans cet espace de travail.',
        'procedure_images_unavailable' => 'Une ou plusieurs images du mode opératoire sont indisponibles, encore en cours de traitement ou hors de cet espace de travail.',
        'procedure_use_library' => 'Ajoutez les images du mode opératoire avec Insérer depuis la médiathèque.',
        'procedure_secure_url' => 'Les images du mode opératoire doivent utiliser leur URL sécurisée de la médiathèque.',
        'recipe_media_mismatch' => 'Le média de recette sélectionné n’appartient pas à cette formule.',
        'upload_store_failed' => 'L’image n’a pas pu être enregistrée. Veuillez réessayer.',
        'retry_failed_only' => 'Seules les images en échec peuvent être relancées.',
        'retry_source_missing' => 'L’import d’origine n’est plus disponible. Supprimez cette image et importez-la de nouveau.',
        'upload_extension' => 'Choisissez une image JPEG, PNG, WebP, HEIC ou HEIF.',
        'upload_invalid_image' => 'Choisissez une image JPEG, PNG, WebP, HEIC ou HEIF valide.',
        'upload_size' => 'L’image ne doit pas dépasser :max Mo.',
        'maximum_images' => 'Choisissez jusqu’à :max images.',
        'selected_images_unavailable' => 'Une ou plusieurs images sélectionnées sont indisponibles ou encore en cours de traitement.',
        'workspace_unavailable' => 'L’espace de travail sélectionné est indisponible.',
        'procedure_image_limit' => 'Le mode opératoire peut contenir jusqu’à :max images.',
        'description_text_only' => 'La description du produit est réservée au texte. Choisissez les images dans la médiathèque.',
    ],
]);
