---
paths:
  - 'app/Services/ContextualHelp/**'
---

# Contextual Help

## Keep contextual help database-authoritative and portable
Contextual help has immutable revision UUIDs, separate draft/published heads, and optimistic locale lock versions. Published translations must reference the currently published English revision; otherwise resolve the entire topic from English. Bootstrap never overwrites owner content; merge-drafts never publishes; restore-empty requires an empty help store. Users have no portable UUID, so export nullable author links rather than querying users.public_id or substituting IDs/emails. PostgreSQL snapshots require repeatable-read or serializable consistency; nested callers must already provide it.
