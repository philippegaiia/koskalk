---
paths:
  - 'resources/views/**'
---

# Views

## Reference URLs via named routes with route()
Generate all URLs and redirects from named routes with route('name', $params); use redirect()->route(...) for redirects and {{ route('name') }} in Blade; do not use url('/path') or action([...]).

## Keep marketing and learning content in the CMS
The CMS at soapkraft.com (WordPress or Ghost) owns the homepage, blog, training, and main end-user documentation. Laravel remains at app.soapkraft.com and owns the application UI, the free soap calculator without registration, concise contextual help, and visible safety/compliance warnings. Link to the CMS for deeper material instead of duplicating it in the application.
