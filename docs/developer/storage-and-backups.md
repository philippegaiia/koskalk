# Media storage and database backups

This document records the production storage decisions for Soapkraft on Laravel Forge, Hetzner, and Cloudflare R2. It is the operational reference for future developers and agents. Never add real credentials, Cloudflare account IDs, database passwords, or API token values to this file.

## Production topology

The application runs at `https://app.soapkraft.com` on a Hetzner server managed by Laravel Forge. Cloudflare proxies the application domain. PostgreSQL currently runs on the same server.

Final media and database backups are stored outside the VPS in three isolated R2 buckets:

| Purpose | Laravel disk | Production bucket | Public access |
| --- | --- | --- | --- |
| Platform catalog media | `r2_public` | `soapkraft-public-media` | `https://media.soapkraft.com` |
| Recipe and user-owned media | `r2_private` | `soapkraft-private-media` | Disabled |
| PostgreSQL backups | `r2_backups` | `soapkraft-backups` | Disabled |

Each bucket uses separate least-privilege R2 credentials. The private media and backup disks intentionally have no configured public URL. R2 credentials live only in Forge's environment management and may be restricted to the application server's IP address.

## Required production environment

The relevant values are:

```dotenv
FILESYSTEM_DISK=local

MEDIA_DISK=r2_public
MEDIA_VISIBILITY=public
RECIPE_MEDIA_DISK=r2_private
USER_MEDIA_DISK=r2_private

R2_REGION=auto
R2_ENDPOINT=https://<cloudflare-account-id>.r2.cloudflarestorage.com

R2_PUBLIC_ACCESS_KEY_ID=<forge-secret>
R2_PUBLIC_SECRET_ACCESS_KEY=<forge-secret>
R2_PUBLIC_BUCKET=soapkraft-public-media
R2_PUBLIC_URL=https://media.soapkraft.com

R2_PRIVATE_ACCESS_KEY_ID=<forge-secret>
R2_PRIVATE_SECRET_ACCESS_KEY=<forge-secret>
R2_PRIVATE_BUCKET=soapkraft-private-media

R2_BACKUP_ACCESS_KEY_ID=<forge-secret>
R2_BACKUP_SECRET_ACCESS_KEY=<forge-secret>
R2_BACKUP_BUCKET=soapkraft-backups

DATABASE_BACKUP_CONNECTION=pgsql
DATABASE_BACKUP_DISK=r2_backups
DATABASE_BACKUP_PREFIX=postgresql
DATABASE_BACKUP_FILENAME_PREFIX=soapkraft
DATABASE_BACKUP_TIMEOUT=900
```

After changing these values in Forge, save the environment and redeploy or clear and rebuild Laravel's configuration cache. Do not run `config:cache` before the environment has been saved.

## Media classification and access

### Public platform catalog media

Images managed by administrators for platform ingredients are public reference data. They are written to `r2_public` and served through `media.soapkraft.com`.

The public bucket's CORS policy must allow reads from `https://app.soapkraft.com` so Filament can preview uploaded images. Development origins should be added only when actually required. The bucket's custom domain is public, but write credentials remain server-side.

Cloudflare can cache a stale CORS response. After changing the bucket CORS policy, purge the relevant cached objects or Cloudflare cache before diagnosing the application again.

### Private recipe and user media

The following are confidential and belong in `r2_private`:

- recipe featured images and rich-content attachments;
- user-owned ingredient images and icons;
- user-owned packaging images;
- future formula, packaging, and confidential uploads.

Private R2 objects are never linked directly from the browser. Laravel returns them through authenticated controllers:

- recipe media requires the recipe `view` authorization check;
- ingredient media requires ownership and an exact stored-path match;
- packaging media requires ownership and an exact stored-path match;
- invalid or unauthorized requests return `404` to avoid disclosing object existence;
- responses use `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.

The database stores object paths, not full R2 URLs. Private media must not gain an `R2_PRIVATE_URL` setting or a public R2 custom domain.

### Upload flow

Livewire temporary uploads use the default `local` disk and the `livewire-tmp/` directory on the Forge server. This is deliberate:

1. The browser uploads the temporary file to Laravel.
2. Laravel validates and reads the temporary file.
3. `MediaStorage` converts the image to WebP, resizes or crops it, and generates a ULID filename.
4. The final file is written server-side to either `r2_public` or `r2_private`.
5. The model stores only the resulting object path.

Do not set Livewire's temporary upload disk to `r2_private` without redesigning the browser preview and CORS flow. Direct private-R2 previews previously produced `403` and CORS failures. Local temporary uploads are cleaned up by Livewire automatically.

### Object paths

User and recipe media are namespaced by the record's UUID public identifier:

```text
recipes/<recipe-public-id>/featured-images/<ulid>.webp
recipes/<recipe-public-id>/rich-content/<ulid>.webp
ingredients/<ingredient-public-id>/featured-images/<ulid>.webp
ingredients/<ingredient-public-id>/icons/<ulid>.webp
packaging-items/<packaging-public-id>/featured-images/<ulid>.webp
```

This layout prevents different records from sharing a directory and allows safe directory cleanup. Folder nesting is primarily for ownership and lifecycle management; it is not a meaningful request-speed optimization for R2.

Platform ingredient media is shared catalog data and currently uses the flatter paths:

```text
ingredients/featured-images/<ulid>.webp
ingredients/icons/<ulid>.webp
```

### Replacement and deletion

Application services delete the previous object when an image is replaced and delete the namespaced directory when a user-owned record or recipe is removed. Platform ingredient deletion removes its public featured image and icon after the guarded database deletion commits.

Deletion must continue to go through `MediaStorage` and the relevant authoring or mutation service. Do not delete database rows directly and assume their R2 objects will disappear automatically.

## Database backup flow

The `backup:database` Artisan command calls `PostgreSqlBackupService` and performs these steps:

1. Create a permission-restricted temporary file under `storage/app/private/database-backups`.
2. Run `pg_dump` in PostgreSQL custom format with `--no-owner` and `--no-privileges`.
3. Reject an empty dump.
4. Calculate a local SHA-256 checksum.
5. Stream the dump to the private `r2_backups` disk.
6. Verify the remote object exists, has the same size, and has the same SHA-256 checksum.
7. Delete a failed remote upload and always remove the local temporary file.

Successful object keys use this structure:

```text
postgresql/YYYY/MM/DD/soapkraft-YYYYMMDDTHHMMSSZ-<ulid>.dump
```

Run an immediate backup from the active Forge release with:

```bash
cd /home/soapkraft/app.soapkraft.com/current
php artisan backup:database
```

Run this immediately before production migrations or other risky database maintenance.

## Scheduling in Forge

Forge has one application-level scheduled job, owned by the `soapkraft` site user, that runs Laravel's scheduler every minute:

```bash
/usr/bin/php8.5 /home/soapkraft/app.soapkraft.com/current/artisan schedule:run
```

The every-minute Forge job does **not** create a database backup every minute. It asks Laravel which code-defined tasks are due. `routes/console.php` schedules `backup:database` once daily at `02:30 UTC`, in production only, with a two-hour overlap lock and `onOneServer()` protection.

Useful checks from the current release are:

```bash
php artisan schedule:list
php artisan backup:database
```

The backup command returns a non-zero exit code and writes the exception to the application log when it fails. A dedicated external backup-success heartbeat is not implemented yet; until it is, periodically confirm that a new object appears under the expected R2 date prefix.

## Retention and R2 lifecycle rules

The `soapkraft-backups` bucket has these lifecycle rules:

- delete uploaded database backups after 60 days;
- keep Cloudflare's default rule that aborts incomplete multipart uploads after 7 days;
- do not make the bucket public;
- do not transition these small daily dumps to Infrequent Access for now.

With one successful daily backup, this provides up to 60 restore points and a recovery point objective of approximately 24 hours. Take additional manual backups before migrations.

## Restore drill

A backup is not proven until it has been restored. Restore drills must use an isolated empty PostgreSQL database, never the live production database.

1. Download a recent `.dump` object from `soapkraft-backups` to a secure temporary location.
2. Inspect the archive:

   ```bash
   pg_restore --list soapkraft-backup.dump
   ```

3. Create an isolated restore-test database with a dedicated temporary owner.
4. Restore with errors treated as fatal:

   ```bash
   pg_restore \
     --exit-on-error \
     --no-owner \
     --no-privileges \
     --dbname=<isolated-restore-database> \
     soapkraft-backup.dump
   ```

5. Verify migrations, the administrator account, platform ingredient counts, formula counts, and representative relationships against the isolated database.
6. Record the backup object key, drill date, restore duration, and any errors.
7. Delete the downloaded dump securely and remove the isolated database after validation.

For a real recovery, restore into a new empty database, validate it, then switch the application connection while the site is in maintenance mode. Do not overwrite the only production database in place.

## Backup boundaries and remaining work

The automated backup covers PostgreSQL only. It does not copy either media bucket.

R2 provides off-VPS object storage, but the current setup does not provide a second independent copy of private media against accidental application deletion, compromised R2 credentials, or account loss. Before storing irreplaceable customer media, add a separate media-copy strategy or an appropriate Cloudflare retention protection and test recovery.

Hetzner server backups are still useful for the operating system, Forge configuration, local logs, and faster whole-server recovery. They complement R2 and PostgreSQL dumps; they do not replace them. Enable Hetzner backups before production customer traffic after the server configuration has stabilized.

The current database dump is protected by TLS in transit and a private R2 bucket, but it is not encrypted by the application before upload. Application-layer backup encryption and externally monitored backup alerts are later hardening items.

## Safe operational checks

These checks reveal configuration names and public endpoints without printing secrets:

```bash
php artisan tinker --execute 'dump([
    "platform" => config("media.disk"),
    "recipes" => config("media.recipe_disk"),
    "user_media" => config("media.user_disk"),
    "backup" => config("database_backup.disk"),
    "public_url" => config("filesystems.disks.r2_public.url"),
]);'

curl -I https://media.soapkraft.com/healthchecks/r2-public.txt
```

Expected disks are `r2_public`, `r2_private`, `r2_private`, and `r2_backups`. Never dump complete filesystem configuration in production because it contains credentials.
