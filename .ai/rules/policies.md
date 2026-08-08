---
paths:
  - 'app/Policies/**'
---

# Policies

## Authorize model access in policy classes
Authorize model access with a {Model}Policy class in app/Policies, auto-discovered (no Gate::define, no AuthServiceProvider wiring). Reuse the shared HandlesWorkspaceAuthorization trait for workspace/role checks.
