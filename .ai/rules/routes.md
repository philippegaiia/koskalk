---
paths:
  - 'routes/*.php'
---

# Routes

## Register route handlers as controller classes
Register HTTP routes with a controller class — [Controller::class, 'method'], a bare Class::class for invokable controllers, or a Route::controller() group — and Route::view() for Livewire-mounted pages. This is the app standard; an inline closure is acceptable for a trivial one-off route, but keep route files class-based to match.

## Assign middleware on routes
Assign middleware at the route level by default — group or per-route ->middleware(...) in routes/web.php — and global cross-cutting middleware in bootstrap/app.php withMiddleware(). Controller middleware (HasMiddleware or #[Middleware]) is acceptable where route-level grouping would be awkward, but the app standard is route-level.

## Rate-limit routes inline with throttle:count,min
Apply simple rate limits inline per route or group with ->middleware('throttle:count,min'). Register a named RateLimiter::for(...) in a service provider when a route needs composite or user-aware limits a single inline throttle cannot express (the app does this for beta-invite-accept).
