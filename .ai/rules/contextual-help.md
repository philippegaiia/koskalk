---
paths:
  - 'app/Services/ContextualHelp/**'
---

# Contextual Help

## Keep contextual help database-authoritative and portable
Contextual help has immutable revision UUIDs, separate draft/published heads, and optimistic locale lock versions. Published translations must reference the currently published English revision; otherwise resolve the entire topic from English. Bootstrap never overwrites owner content; merge-drafts never publishes; restore-empty requires an empty help store. Users have no portable UUID, so export nullable author links rather than querying users.public_id or substituting IDs/emails. PostgreSQL snapshots require repeatable-read or serializable consistency; nested callers must already provide it.

## Publish initial English help locally for contextual review
The owner wants newly authored English help published locally so it can be reviewed in the actual UI. Back up help content, bootstrap missing topics, and publish through PublishHelpTopic. Keep newer drafts of already published topics intact unless the owner asks to publish those edits. Translations remain manual and must not be overwritten or automatically published. This workflow does not authorize production deployment or change bootstrap's draft-only behavior.

## Retire backup files without deleting the audit record
Admin backup removal deletes the stored file then marks removed_at; retain HelpContentExport audit rows. Only succeeded/failed exports can be removed under a row lock. List, download, retry, and worker claim paths must reject removed exports so queued retries cannot recreate them. Help topics, revisions, and translations are not affected.
