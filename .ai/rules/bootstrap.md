---
paths:
  - bootstrap/app.php
---

# Bootstrap

## Assign middleware on routes
Assign middleware at the route level by default — group or per-route ->middleware(...) in routes/web.php — and global cross-cutting middleware in bootstrap/app.php withMiddleware(). Controller middleware (HasMiddleware or #[Middleware]) is acceptable where route-level grouping would be awkward, but the app standard is route-level.
