---
paths:
  - 'lang/**/*.php'
---

# Lang

## Custom validation messages live in per-domain lang files
Define custom validation messages under a 'validation' key in the matching domain lang file (lang/en/<domain>.php) and resolve them with __() — the app standard. Form Request messages()/attributes() are acceptable for request-specific wording, but the lang-file approach centralizes translations. Do not edit the framework's lang/*/validation.php.

## Author UI strings as short dotted keys in lang/en
Author user-facing strings as short dotted keys in lang/en/<group>.php and call them with __('group.key') — the app standard. JSON sentence keys are acceptable for simple strings, but group files support the DB-override mechanism. Do not add app text to the lang/*.json framework files.

## Register new interface groups in the translation catalogue
Adding lang/en strings alone does not make them discoverable by the database translation tools. Register new groups/key patterns in config/interface-translations.php and add matching six-locale catalogue rows in database/seeders/data/interface-translations.json. Localize invitation pages and notification copy through dotted keys too; import missing translations with preserve-existing when retaining administrator wording.
