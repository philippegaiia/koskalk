---
paths:
  - 'app/Providers/*.php'
  - 'app/Providers/**'
---

# Providers

## Rate-limit routes inline with throttle:count,min
Apply simple rate limits inline per route or group with ->middleware('throttle:count,min'). Register a named RateLimiter::for(...) in a service provider when a route needs composite or user-aware limits a single inline throttle cannot express (the app does this for beta-invite-accept).

## Resolve Vite assets lazily in Filament panel providers
Panel providers register during every artisan command boot (package:discover, queue workers, scheduler). Never call Vite::asset() (or anything reading public/build/manifest.json) eagerly in provider/panel configuration - a fresh Forge release has no built assets yet and every artisan command dies with ViteManifestNotFoundException. Resolve assets lazily instead, e.g. a ->renderHook() closure that builds the <script>/<link> tag at render time. viteTheme() is already lazy; only inline Vite::asset() calls are dangerous. Regression test: tests/Feature/AdminPanelAssetRegistrationTest.php.
