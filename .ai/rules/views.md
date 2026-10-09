---
paths:
  - 'resources/views/**'
---

# Views

## Reference URLs via named routes with route()
Generate all URLs and redirects from named routes with route('name', $params); use redirect()->route(...) for redirects and {{ route('name') }} in Blade; do not use url('/path') or action([...]).

## Keep marketing and learning content in the CMS
The CMS at soapkraft.com (WordPress or Ghost) owns the homepage, blog, training, and main end-user documentation. Laravel remains at app.soapkraft.com and owns the application UI, the free soap calculator without registration, concise contextual help, and visible safety/compliance warnings. Link to the CMS for deeper material instead of duplicating it in the application.

## Alpine event names must not begin with a Blade directive name
Blade compiles any `@word` it has registered, before Alpine ever sees it. `@production-row-actions-opened.window="..."` compiles as Laravel's `@production` environment directive, so the view fails with "syntax error, unexpected end of file, expecting elseif/else/endif". Prefix the event instead (`@bench-production-row-actions-opened`); `@formula-...`, `@click`, `@scroll`, `@resize` and `@keydown` are safe because those words are not directives.

## Alpine methods read their component root from init()
Inside a method called from an event expression, Alpine resolves `this.$el` to the element the event fired on (a menu item or button), not the `x-data` root, so `this.$el.dataset` silently returns undefined. Components that keep configuration in data attributes on their root must store the root in `init()` and read it from there; `$root` is not safe when a nested `x-data` sits between. The app shell also loads `@livewireScripts` explicitly, because Livewire (and its bundled Alpine) is only auto-injected on pages that render a Livewire component.

## Print and export buttons share one look
Every print or export control is an outline button with a leading icon and a visible label: `class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"`, `<x-action-icon name="printer" />` for anything that opens a printable document, `<x-action-icon name="download" />` for anything that produces a file, and a trailing `<x-action-icon name="chevron-down" />` only when a format menu opens. Labels are verb + object ("Print sheet", "Export costing"). Do not use icon-only buttons or a filled primary button for outputs; they sit beside the page's real primary action. Keep plain `<button>`/`<a>` markup rather than a Blade component, because `:`-prefixed Alpine bindings on a component tag are evaluated as PHP. OutputActionStyleTest enforces this.
