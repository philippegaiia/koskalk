---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Use Form Request classes for controller input validation
Default to Form Request classes on write endpoints: type-hint the request and read input through $request->validated(), keeping rules in app/Http/Requests. Inline $request->validate() is acceptable for one-off validation, but the app standard is Form Requests.

## Retrieve request params with typed getters
Prefer typed request getters such as $request->string() or $request->boolean() for scalar params. $request->input()/->all()/->only() are acceptable for nested or dynamic keys, but the app standard is typed getters; read validated form input via $request->validated() and use ->query() for raw query-string pass-through.

## JSON responses use inline arrays via response()->json()
Return JSON from controllers as inline arrays built with response()->json([...]); do not create ApiResource classes or an app/Http/Resources layer.

## Preserve recorded price currencies in exports
CurrentMaterialPrice stores its own currency; changing a workspace default does not convert or rewrite existing prices. Export each recorded amount with its recorded currency and use a currency-neutral amount header when rows may differ. Do not relabel an old amount using today's default currency or imply an exchange conversion.
