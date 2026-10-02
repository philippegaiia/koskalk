---
paths:
  - 'app/{Actions,Services}/**'
---

# Actions Services

## Production mutations require mounted editing contexts
Existing-production commands must enter ProductionMutationGuard with an explicit ProductionEditingContext: all selected mounted revisions and matching tab ownership, or a server-created temporary register context that refuses any active lease. Guard the durable production parent before child tasks, journal/documents and output stock. Internal date, snapshot and task helpers require an active ProductionMutationScope; creation uses withinCreated. Return only the committed command acknowledgment, increment each changed parent once, preserve true no-ops, and retain WorkspaceWriteLock's PostgreSQL MVCC fence without changing sharing isolation.
