---
paths:
  - 'resources/views/**'
---

# Views

## Reference URLs via named routes with route()
Generate all URLs and redirects from named routes with route('name', $params); use redirect()->route(...) for redirects and {{ route('name') }} in Blade; do not use url('/path') or action([...]).

## Keep public documentation in WordPress
WordPress owns the public soapkraft.com site, marketing, editorial content, and long-form end-user documentation. Laravel remains at app.soapkraft.com and keeps concise task copy, contextual help, and visible safety/compliance warnings; link to WordPress for deeper material instead of duplicating it.
