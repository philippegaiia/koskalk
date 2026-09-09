---
paths:
  - 'resources/views/components/workflow-action-bar.blade.php'
---

# Views Components

## Workflow bars follow the application content width
The shared fixed bottom workflow bar defaults to max-w-app so it aligns with the 1184px application canvas, including Production Bench forms. Keep its panel translucent at 72% with backdrop blur; one-off bottom bars such as the formula status bar must use the same width token and opacity.
