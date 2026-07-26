<?php

$translations = array_replace_recursive(require lang_path('en/media_library.php'), [
    'panel' => ['settings' => 'Ajustes', 'settings_for' => 'Abrir ajustes de :name', 'close' => 'Cerrar detalles de la imagen', 'tabs_label' => 'Detalles de la imagen', 'search_usage' => 'Buscar en estos usos', 'no_usages' => 'Esta imagen aún no se utiliza.', 'no_matching_usages' => 'Ningún uso coincide con esta búsqueda.', 'groups' => ['recipes' => 'Recetas', 'ingredients' => 'Ingredientes', 'packaging' => 'Envases', 'other' => 'Otros'], 'delete' => 'Eliminar', 'delete_confirm' => '¿Eliminar « :name »? Esta acción no se puede deshacer.'],
    'title' => 'Biblioteca multimedia', 'eyebrow' => 'Multimedia del espacio de trabajo', 'description' => 'Sube una vez y reutiliza la misma imagen en productos, ingredientes, envases e instrucciones de fabricación.', 'upload' => 'Subir imagen', 'uploading' => 'Subiendo…', 'choose_upload' => 'Elige una imagen para subir', 'uploads_blocked' => 'Las nuevas subidas están bloqueadas hasta eliminar un recurso sin usar o ampliar el plan. Los recursos existentes siguen disponibles.', 'usage' => '{0} :count usos|{1} :count uso|[2,*] :count usos', 'original_filename' => 'Original: :name', 'display_name' => 'Nombre visible', 'search_placeholder' => 'Nombre o archivo original', 'search' => 'Buscar', 'rename' => 'Cambiar nombre', 'save_name' => 'Guardar nombre', 'usage_details' => 'Usado por', 'missing_target' => 'Elemento eliminado', 'detach_before_removing' => 'Desvincula esta imagen de todas partes antes de eliminarla.', 'quota' => ['unlimited' => ':used recursos usados · Sin límite', 'limited' => ':used de :limit recursos usados'], 'filters' => ['aria_label' => 'Filtros multimedia', 'processing_status' => 'Estado de procesamiento', 'all' => 'Todos', 'used' => 'Usados', 'unused' => 'Sin usar'], 'statuses' => ['all' => 'Cualquier estado', 'processing' => 'Procesando', 'ready' => 'Listo', 'failed' => 'Fallido'], 'processing_stages' => ['queued' => 'en cola', 'validating' => 'validando', 'normalizing' => 'normalizando', 'converting' => 'convirtiendo'], 'empty' => ['title' => 'No se encontraron recursos', 'description' => 'Sube una imagen o ajusta los filtros.'], 'actions' => ['retry' => 'Reintentar', 'remove' => 'Eliminar', 'remove_unused' => 'Eliminar recurso sin usar'], 'crop' => ['adjust' => 'Ajustar recorte cuadrado', 'horizontal' => 'Punto focal horizontal', 'vertical' => 'Punto focal vertical', 'save' => 'Guardar punto focal'], 'picker' => ['choose' => 'Elegir de la biblioteca multimedia', 'choose_multiple' => 'Elegir imágenes', 'clear' => 'Borrar', 'close' => 'Cerrar selector multimedia', 'library' => 'Biblioteca', 'upload_new' => 'Subir nueva', 'upload' => 'Subir', 'upload_heading' => 'Subir a la biblioteca multimedia', 'upload_description' => 'El selector permanece abierto durante el procesamiento. La imagen se seleccionará automáticamente cuando esté lista.', 'image' => 'Imagen', 'select_one' => 'Selecciona una imagen lista.', 'select_multiple' => 'Selecciona hasta :count imágenes listas.', 'no_selection' => 'No hay ninguna imagen seleccionada.', 'search_placeholder' => 'Buscar por nombre o archivo original', 'search_label' => 'Buscar recursos multimedia', 'empty_title' => 'Tu biblioteca multimedia está vacía', 'empty_description' => 'Sube una imagen y vuelve aquí para seleccionarla.', 'upload_unavailable' => 'Las nuevas subidas están bloqueadas. Los recursos existentes siguen disponibles.', 'processing' => 'Procesando imagen subida', 'failed' => 'Falló el procesamiento de la imagen', 'refresh_failed' => 'No se pudo actualizar el estado. Inténtalo de nuevo.', 'retry' => 'Reintentar', 'remove' => 'Eliminar', 'done' => 'Listo', 'manage' => 'Gestionar biblioteca multimedia', 'processing_progress' => ':progress % procesado', 'processing_failed' => 'Procesamiento fallido'],
    'roles' => ['recipe_featured' => 'Imagen destacada de receta', 'recipe_sop' => 'Instrucción de receta', 'ingredient_main' => 'Imagen principal de ingrediente', 'ingredient_icon_override' => 'Icono de ingrediente', 'packaging_main' => 'Imagen principal de envase'], 'messages' => ['renamed' => 'Se cambió el nombre de «:name».', 'upload_processing' => '«:name» se está procesando.', 'retry_processing' => '«:name» se está procesando de nuevo.', 'removed' => 'Se eliminó «:name».', 'focal_refreshing' => 'Se está actualizando el punto focal del recorte cuadrado.'], 'validation' => ['display_name_required' => 'Introduce un nombre visible.', 'display_name_max' => 'El nombre visible no puede superar los 255 caracteres.', 'focal_point' => 'Elige un punto focal dentro de la imagen.'],
]);

return array_replace_recursive($translations, [
    'choose_files' => 'Elegir imágenes',
    'selected_files' => '{1} :count imagen seleccionada|[2,*] :count imágenes seleccionadas',
    'upload_selected' => 'Subir imágenes seleccionadas',
    'batch_limit' => 'Máximo :max imágenes por carga. Reduce la selección en :count para continuar.',
    'batch_position' => 'Subiendo :current de :total',
    'remove_file' => 'Eliminar :name',
    'batch_file_failed' => 'No se pudo subir :name. Inténtalo de nuevo o elimina el archivo.',
    'batch_quota' => 'Capacidad multimedia restante de tu plan: :count.',
    'picker' => ['insert_from_media_library' => 'Insertar desde la biblioteca multimedia', 'choose_file' => 'Elegir imagen', 'no_file_selected' => 'Ninguna imagen seleccionada', 'load_more' => 'Cargar más', 'polling_stopped' => 'Las actualizaciones de estado se detuvieron tras varios errores de conexión. Vuelve a intentar la subida.'],
    'validation' => [
        'picker_ready_workspace' => 'Elige una imagen lista de este espacio de trabajo.',
        'procedure_images_unavailable' => 'Una o varias imágenes del procedimiento no están disponibles, siguen procesándose o pertenecen a otro espacio de trabajo.',
        'procedure_use_library' => 'Añade las imágenes del procedimiento con Insertar desde la biblioteca multimedia.',
        'procedure_secure_url' => 'Las imágenes del procedimiento deben usar su URL segura de la biblioteca multimedia.',
        'recipe_media_mismatch' => 'El recurso de receta seleccionado no pertenece a esta fórmula.',
        'upload_store_failed' => 'No se pudo guardar la imagen. Inténtalo de nuevo.',
        'retry_failed_only' => 'Solo se pueden reintentar las imágenes fallidas.',
        'retry_source_missing' => 'La carga original ya no está disponible. Elimina esta imagen y vuelve a subirla.',
        'upload_extension' => 'Elige una imagen JPEG, PNG, WebP, HEIC o HEIF.',
        'upload_invalid_image' => 'Elige una imagen JPEG, PNG, WebP, HEIC o HEIF válida.',
        'upload_size' => 'La imagen no debe superar los :max MB.',
        'maximum_images' => 'Elige hasta :max imágenes.',
        'selected_images_unavailable' => 'Una o varias imágenes seleccionadas no están disponibles o siguen procesándose.',
        'workspace_unavailable' => 'El espacio de trabajo seleccionado no está disponible.',
        'procedure_image_limit' => 'El procedimiento de fabricación puede contener hasta :max imágenes.',
        'description_text_only' => 'La descripción del producto solo admite texto. Elige las imágenes en la biblioteca multimedia.',
    ],
]);
