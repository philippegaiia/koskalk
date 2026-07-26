<?php

$translations = array_replace_recursive(require lang_path('en/media_library.php'), [
    'panel' => ['settings' => 'Impostazioni', 'settings_for' => 'Apri le impostazioni di :name', 'close' => 'Chiudi dettagli immagine', 'tabs_label' => 'Dettagli immagine', 'search_usage' => 'Cerca in questi utilizzi', 'no_usages' => 'Questa immagine non è ancora utilizzata.', 'no_matching_usages' => 'Nessun utilizzo corrisponde a questa ricerca.', 'groups' => ['recipes' => 'Ricette', 'ingredients' => 'Ingredienti', 'packaging' => 'Confezioni', 'other' => 'Altro'], 'delete' => 'Elimina', 'delete_confirm' => 'Eliminare “:name”? Questa azione non può essere annullata.'],
    'title' => 'Libreria multimediale', 'eyebrow' => 'Media dello spazio di lavoro', 'description' => 'Carica una volta e riutilizza la stessa immagine in prodotti, ingredienti, confezioni e istruzioni di produzione.', 'upload' => 'Carica immagine', 'uploading' => 'Caricamento…', 'choose_upload' => 'Scegli un’immagine da caricare', 'uploads_blocked' => 'I nuovi caricamenti sono bloccati finché non rimuovi una risorsa inutilizzata o aggiorni il piano. Le risorse esistenti restano disponibili.', 'usage' => '{0} :count utilizzi|{1} :count utilizzo|[2,*] :count utilizzi', 'original_filename' => 'Originale: :name', 'display_name' => 'Nome visualizzato', 'search_placeholder' => 'Nome o file originale', 'search' => 'Cerca', 'rename' => 'Rinomina', 'save_name' => 'Salva nome', 'usage_details' => 'Usato da', 'missing_target' => 'Elemento eliminato', 'detach_before_removing' => 'Scollega questa immagine ovunque prima di rimuoverla.', 'quota' => ['unlimited' => ':used risorse usate · Illimitate', 'limited' => ':used risorse usate su :limit'], 'filters' => ['aria_label' => 'Filtri multimediali', 'processing_status' => 'Stato elaborazione', 'all' => 'Tutti', 'used' => 'Usati', 'unused' => 'Inutilizzati'], 'statuses' => ['all' => 'Qualsiasi stato', 'processing' => 'Elaborazione', 'ready' => 'Pronto', 'failed' => 'Non riuscito'], 'processing_stages' => ['queued' => 'in coda', 'validating' => 'verifica', 'normalizing' => 'normalizzazione', 'converting' => 'conversione'], 'empty' => ['title' => 'Nessun media trovato', 'description' => 'Carica un’immagine o modifica i filtri.'], 'actions' => ['retry' => 'Riprova', 'remove' => 'Rimuovi', 'remove_unused' => 'Rimuovi risorsa inutilizzata'], 'crop' => ['adjust' => 'Regola ritaglio quadrato', 'horizontal' => 'Punto focale orizzontale', 'vertical' => 'Punto focale verticale', 'save' => 'Salva punto focale'], 'picker' => ['choose' => 'Scegli dalla libreria multimediale', 'choose_multiple' => 'Scegli immagini', 'clear' => 'Cancella', 'close' => 'Chiudi selettore media', 'library' => 'Libreria', 'upload_new' => 'Nuovo caricamento', 'upload' => 'Carica', 'upload_heading' => 'Carica nella libreria multimediale', 'upload_description' => 'Il selettore resta aperto durante l’elaborazione. L’immagine verrà selezionata automaticamente quando sarà pronta.', 'image' => 'Immagine', 'select_one' => 'Seleziona un’immagine pronta.', 'select_multiple' => 'Seleziona fino a :count immagini pronte.', 'no_selection' => 'Nessuna immagine selezionata.', 'search_placeholder' => 'Cerca per nome o file originale', 'search_label' => 'Cerca risorse multimediali', 'empty_title' => 'La libreria multimediale è vuota', 'empty_description' => 'Carica un’immagine, poi torna qui per selezionarla.', 'upload_unavailable' => 'I nuovi caricamenti sono bloccati. Le risorse pronte esistenti restano selezionabili.', 'processing' => 'Elaborazione immagine caricata', 'failed' => 'Elaborazione immagine non riuscita', 'refresh_failed' => 'Impossibile aggiornare lo stato. Riprova.', 'retry' => 'Riprova', 'remove' => 'Rimuovi', 'done' => 'Fatto', 'manage' => 'Gestisci libreria multimediale', 'processing_progress' => ':progress% elaborato', 'processing_failed' => 'Elaborazione non riuscita'],
    'roles' => ['recipe_featured' => 'Immagine principale ricetta', 'recipe_sop' => 'Istruzione ricetta', 'ingredient_main' => 'Immagine principale ingrediente', 'ingredient_icon_override' => 'Icona ingrediente', 'packaging_main' => 'Immagine principale confezione'], 'messages' => ['renamed' => '“:name” è stato rinominato.', 'upload_processing' => '“:name” è in elaborazione.', 'retry_processing' => '“:name” è di nuovo in elaborazione.', 'removed' => '“:name” è stato rimosso.', 'focal_refreshing' => 'Il punto focale del ritaglio quadrato è in aggiornamento.'], 'validation' => ['display_name_required' => 'Inserisci un nome visualizzato.', 'display_name_max' => 'Il nome visualizzato non può superare 255 caratteri.', 'focal_point' => 'Scegli un punto focale all’interno dell’immagine.'],
]);

return array_replace_recursive($translations, [
    'choose_files' => 'Scegli immagini',
    'selected_files' => '{1} :count immagine selezionata|[2,*] :count immagini selezionate',
    'upload_selected' => 'Carica le immagini selezionate',
    'batch_limit' => 'Massimo :max immagini per caricamento. Riduci la selezione di :count per continuare.',
    'batch_position' => 'Caricamento :current di :total',
    'remove_file' => 'Rimuovi :name',
    'batch_file_failed' => 'Impossibile caricare :name. Riprova o rimuovi il file.',
    'batch_quota' => 'Capacità multimediale rimanente del piano: :count.',
    'picker' => ['insert_from_media_library' => 'Inserisci dalla libreria multimediale', 'choose_file' => 'Scegli immagine', 'no_file_selected' => 'Nessuna immagine selezionata', 'load_more' => 'Carica altro', 'polling_stopped' => 'Gli aggiornamenti di stato sono stati interrotti dopo ripetuti errori di connessione. Riprova il caricamento.'],
    'validation' => [
        'picker_ready_workspace' => 'Scegli un’immagine pronta da questo spazio di lavoro.',
        'procedure_images_unavailable' => 'Una o più immagini della procedura non sono disponibili, sono ancora in elaborazione o appartengono a un altro spazio di lavoro.',
        'procedure_use_library' => 'Aggiungi le immagini della procedura con Inserisci dalla libreria multimediale.',
        'procedure_secure_url' => 'Le immagini della procedura devono usare il loro URL sicuro della libreria multimediale.',
        'recipe_media_mismatch' => 'Il contenuto multimediale della ricetta selezionato non appartiene a questa formula.',
        'upload_store_failed' => 'Non è stato possibile salvare l’immagine. Riprova.',
        'retry_failed_only' => 'È possibile riprovare solo le immagini non riuscite.',
        'retry_source_missing' => 'Il caricamento originale non è più disponibile. Rimuovi questa immagine e caricala di nuovo.',
        'upload_extension' => 'Scegli un’immagine JPEG, PNG, WebP, HEIC o HEIF.',
        'upload_invalid_image' => 'Scegli un’immagine JPEG, PNG, WebP, HEIC o HEIF valida.',
        'upload_size' => 'L’immagine non deve superare :max MB.',
        'maximum_images' => 'Scegli fino a :max immagini.',
        'selected_images_unavailable' => 'Una o più immagini selezionate non sono disponibili o sono ancora in elaborazione.',
        'workspace_unavailable' => 'Lo spazio di lavoro selezionato non è disponibile.',
        'procedure_image_limit' => 'La procedura di produzione può contenere fino a :max immagini.',
        'description_text_only' => 'La descrizione del prodotto è riservata al testo. Scegli le immagini dalla libreria multimediale.',
    ],
]);
