<?php

return [
    'bulk' => [
        'publish' => 'Publish selected English drafts',
        'publish_description' => 'Publish the selected saved English drafts. Changed, archived or missing drafts are skipped. Unselected topics stay unchanged. Existing translations fall back to English until translated against the new English version.',
        'translate' => 'Translate selected topics',
        'translate_description' => 'Publish selected English drafts, then translate into the chosen languages. Current translations, existing manual drafts and pending candidates are skipped. Each topic and language uses an AI request.',
        'languages' => 'Languages',
        'request_count' => 'Up to :count AI requests. Limit: :limit per batch. Topics with existing work are skipped.',
        'limit' => 'Select fewer topics or languages. A batch allows at most :limit translation requests.',
        'review' => 'Review translations before publishing',
        'review_help' => 'Checked: keep translation candidates for manual review. Unchecked: automatically publish successful translations if their English source and target remain unchanged.',
        'completed' => 'Help bulk action completed',
        'result' => ':published English drafts published; :queued translations queued; :skipped topics or languages skipped because they are current, changed, have work to review or could not be processed.',
    ],
    'validation' => [
        'plain_text' => 'Enter visible plain text without HTML.',
        'markdown' => 'Use paragraphs, headings 2 or 3, emphasis, lists, and links. HTML, images, code, and embeds are not supported.',
        'link' => 'Use an HTTPS link or an app path beginning with a single slash.',
        'conflict' => 'This help topic changed after you opened it. Reload and review the latest version before saving.',
        'archived' => 'Unarchive this topic before editing or publishing it.',
        'source' => 'Select an English revision belonging to this topic.',
        'publication' => 'Review and save the latest revision before publishing.',
        'translation_source' => 'Review this translation against the current published English before publishing.',
    ],
];
