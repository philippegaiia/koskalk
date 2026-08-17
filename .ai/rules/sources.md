---
paths:
  - 'app/Services/IngredientEnrichment/Sources/**'
---

# Sources

## Preserve source URL query strings
Some deterministic source URLs, including EUR-Lex CELEX documents, carry required identifiers in their existing query string. When no additional query parameters are needed, call the HTTP client without an empty query array because Guzzle replaces the URL query and would otherwise request the wrong endpoint.
