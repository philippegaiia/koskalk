# Deployment security checklist

This checklist is for the private, invite-only Koskalk MVP on Laravel Forge and Hetzner behind Cloudflare. Public calculator, public formulas, sharing, invitations, and collaboration remain disabled.

## Server layout

- The current 1 vCPU, 2 GB RAM Hetzner server is a trial environment for learning Forge and validating deployment. Upgrade it to at least 2 vCPU and 4 GB RAM before production traffic; 4 vCPU and 8 GB RAM remains the comfortable combined app/database target.
- A separate database server is an operational choice, not a launch requirement. Splitting early adds a second failure domain, private networking, TLS, backup, and monitoring work.
- If PostgreSQL is separated, start with at least 2 dedicated vCPU, 4 GB RAM, and 40–80 GB NVMe storage. Two GB can run a very small database but leaves little safe headroom for PostgreSQL, the OS, maintenance, and traffic spikes.
- Whether combined or split, alert at 70% disk usage and act before 80%. Formula media, logs, database growth, and backups must not silently fill a 40 GB disk.
- Never expose PostgreSQL port 5432 publicly. On a second server, bind it to Hetzner's private network, allow only the application server, use a dedicated least-privilege database user, set `DB_SSLMODE=require`, and keep SSH restricted.

## Cloudflare and network

- Set Cloudflare SSL/TLS to **Full (strict)** with a valid origin certificate.
- Firewall the origin to Cloudflare HTTP/HTTPS ranges and the administrator's SSH IPs. Do this before setting `TRUSTED_PROXIES=*`; otherwise clients can forge forwarded IP and HTTPS headers.
- Set `TRUSTED_PROXIES=*` only when the origin firewall guarantees that web traffic reaches Laravel through Cloudflare. Alternatively list the exact trusted proxy addresses.
- Disable direct public access to database, Redis, and queue services. Do not place database administration tools on a public route.
- Keep Hetzner and Forge accounts protected by MFA; use separate operator accounts rather than shared credentials.

## Production environment

Set and verify at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.soapkraft.com
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

FILESYSTEM_DISK=local
MEDIA_DISK=r2_public
MEDIA_VISIBILITY=public
RECIPE_MEDIA_DISK=r2_private
USER_MEDIA_DISK=r2_private

QUEUE_CONNECTION=database
DB_SSLMODE=prefer
```

Platform catalog media is stored in the public R2 bucket. Recipe and user-owned media are stored in the private R2 bucket and served only through authenticated application controllers. Database dumps use a third private R2 bucket with separate credentials. See [developer/storage-and-backups.md](./developer/storage-and-backups.md) for the current flow, environment keys, retention, and restore procedure.

Use `DB_SSLMODE=require` when PostgreSQL is on the second server. Generate a unique `APP_KEY`; never copy development secrets. Store database, mail, Paddle, Cloudflare, Forge, and backup credentials only in the deployment secret store. Rotate any credential that has appeared in a repository, terminal transcript, ticket, or chat.

## Deploy and application

- Deploy only over HTTPS. Put the application in maintenance mode, take the verified backup, run `php artisan migrate --force`, then run `php artisan app:migrate-recipe-media-to-private`. The media command copies and verifies every legacy formula file, updates database references transactionally, and only then removes the old public/private legacy paths; it is safe to rerun. Cache production configuration, routes, events, and views afterward.
- Run a persistent Forge queue worker for the database queue and configure the scheduler every minute. Restart workers after each deployment.
- Provision the initial verified owner interactively with `php artisan app:provision-workspace-owner owner@example.com`; never pass its password on the command line.
- Confirm `/register`, `/calculator`, and `/calculator/draft` return 404; unverified accounts cannot enter the dashboard.
- Confirm formula and production URLs contain UUIDs, numeric URLs return 404, and another account receives 404 for owner formula URLs.
- Confirm platform ingredients are visible in the authenticated catalog but their save and delete operations are blocked.
- Confirm formula images load through authenticated `/dashboard/recipes/{uuid}/media/...` routes and no formula image is available under `/storage`.
- Verify Paddle webhook signatures, mail delivery, queue processing, scheduled jobs, export throttles, and the `/up` health endpoint.

## Backups and recovery

- Take automated PostgreSQL backups at least daily, retain multiple generations, encrypt them, and copy them off the VPS/provider account.
- Final confidential media is stored in private R2 rather than `storage/app/private`; Livewire temporary uploads still use local storage and are cleaned up automatically. Database-only backups remain incomplete because they do not copy the private media bucket.
- Do not treat same-server snapshots as the only backup. Keep an offsite copy with separate credentials.
- Test a restore into an isolated server before launch and on a recurring schedule. Record recovery time, required secrets, and the exact restore procedure.
- Take a verified backup immediately before every production migration. Keep rollback-safe application releases available in Forge.

## Monitoring and incident basics

- Alert on HTTP 5xx rate, queue failures, disk, memory, CPU, database connections, backup failure, certificate expiry, and repeated authentication throttles.
- Keep application logs private and avoid logging passwords, session IDs, authorization headers, Paddle payload secrets, full exports, or confidential formula content.
- Send exceptions to a private error tracker with data scrubbing. Restrict Forge, Cloudflare, Hetzner, database, backup, and error-tracker access to the minimum operators.
- Maintain a short incident procedure: revoke sessions and credentials, block abusive IPs at Cloudflare, preserve relevant logs, restore if necessary, and notify affected users when required.
