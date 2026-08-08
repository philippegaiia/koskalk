---
paths:
  - 'resources/views/**'
---

# Views

## Reference URLs via named routes with route()
Generate all URLs and redirects from named routes with route('name', $params); use redirect()->route(...) for redirects and {{ route('name') }} in Blade; do not use url('/path') or action([...]).
