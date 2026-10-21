---
paths:
  - 'app/Services/ContextualHelp/**'
---

# Contextual Help

## Keep contextual help database-authoritative and portable
Contextual help has immutable revision UUIDs, separate draft/published heads, and optimistic locale lock versions. Published translations must reference the currently published English revision; otherwise resolve the entire topic from English. Bootstrap never overwrites owner content; merge-drafts never publishes; restore-empty requires an empty help store. Users have no portable UUID, so export nullable author links rather than querying users.public_id or substituting IDs/emails. PostgreSQL snapshots require repeatable-read or serializable consistency; nested callers must already provide it.

## Publish initial English help locally for contextual review
The owner wants newly authored English help published locally so it can be reviewed in the actual UI. Back up help content, bootstrap missing topics, and publish through PublishHelpTopic. Keep newer drafts of already published topics intact unless the owner asks to publish those edits. Translations require manual review by default. The owner authorized opt-in automatic publication through the selected-topics translation action on 2026-10-21: an administrator must explicitly uncheck review before publishing, and the immutable request records that choice. Recheck requester admin access, current English draft and published heads, target lock version, active locale and archive state before automatic acceptance/publication. Preserve existing manual drafts, current translations and pending work; imports never authorize publication or paid calls. Administrators may also explicitly bulk-accept and publish existing completed candidates without individual review or another AI request; preserve manual drafts and snapshot the candidate identity, source and target locks. This does not change the original request’s automatic-publication flag. Show saved requested/reported models and reasoning, rather than inferring them from current configuration. Bulk runs are limited to 100 topics and 600 topic-language pairs. This workflow does not authorize production deployment or change bootstrap's draft-only behavior.

## Retire backup files without deleting the audit record
Admin backup removal deletes the stored file then marks removed_at; retain HelpContentExport audit rows. Only succeeded/failed exports can be removed under a row lock. List, download, retry, and worker claim paths must reject removed exports so queued retries cannot recreate them. Help topics, revisions, and translations are not affected.

## Combine publication backups and skip unchanged scheduled snapshots
Publication, withdrawal, and archive changes request a shared pending publication export after commit, under a cache lock. Dispatch delays that export until two minutes after creation, including recovery dispatches. Never combine with a running export. Scheduled snapshots compare complete topics/history/audit data against a checksum-verified retained backup and skip unchanged content; manual and pre-import snapshots remain unconditional.
