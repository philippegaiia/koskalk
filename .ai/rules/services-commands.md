---
paths:
  - 'app/{Services/QueuedFileExports.php,Jobs/GenerateFileExport.php,Console/Commands/PruneFileExports.php}'
---

# Services Commands

## Run bulk list exports on the dedicated exports queue
Ingredient, inventory material and stock lot files share the database-backed exports queue. Keep generated files private and requester-only; recheck live membership, selected workspace and module access at generation and download. Bound concurrent work and retained files separately; cleanup must remove OpenSpout scratch files and recover interrupted jobs. Printing and bounded formula outputs remain synchronous.

## Run bulk list exports on the dedicated exports queue
Ingredient, packaging, inventory material and stock lot files share the database-backed exports queue. Keep files private and requester-only; recheck membership and selected workspace at generation/download. Only inventory/stock exports require Production Bench access. Bound concurrent work and retained files separately; cleanup removes OpenSpout scratch files and recovers interrupted jobs. Printing and bounded formula outputs remain synchronous.
