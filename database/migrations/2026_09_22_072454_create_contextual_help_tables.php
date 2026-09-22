<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_topics', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key', 160)->unique();
            $table->string('domain', 40);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('help_topic_locales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_topic_id')->constrained()->restrictOnDelete();
            $table->string('locale', 16)->index();
            $table->foreign('locale')->references('code')->on('supported_locales')->restrictOnDelete();
            $table->unsignedBigInteger('latest_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('published_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['help_topic_id', 'locale']);
            $table->index(['latest_revision_id', 'id'], 'help_locale_latest_index');
            $table->index(['published_revision_id', 'id'], 'help_locale_published_index');
        });

        Schema::create('help_topic_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('help_topic_locale_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title', 160);
            $table->text('summary');
            $table->text('body_markdown')->nullable();
            $table->foreignId('source_english_revision_id')->nullable()->index()->constrained('help_topic_revisions')->restrictOnDelete();
            $table->string('origin', 24);
            $table->string('ai_model', 160)->nullable();
            $table->string('prompt_version', 100)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['help_topic_locale_id', 'revision_number'], 'help_revision_number_unique');
            $table->unique(['id', 'help_topic_locale_id'], 'help_revision_owner_unique');
        });

        Schema::table('help_topic_locales', function (Blueprint $table): void {
            $table->foreign(['latest_revision_id', 'id'], 'help_locale_latest_foreign')->references(['id', 'help_topic_locale_id'])->on('help_topic_revisions')->restrictOnDelete();
            $table->foreign(['published_revision_id', 'id'], 'help_locale_published_foreign')->references(['id', 'help_topic_locale_id'])->on('help_topic_revisions')->restrictOnDelete();
        });

        Schema::create('help_translation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('help_topic_locale_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('source_english_revision_id')->index()->constrained('help_topic_revisions')->restrictOnDelete();
            $table->unsignedInteger('expected_target_lock_version');
            $table->string('status', 24)->default('pending')->index();
            $table->json('result')->nullable();
            $table->unsignedBigInteger('accepted_revision_id')->nullable();
            $table->foreign(['accepted_revision_id', 'help_topic_locale_id'], 'help_request_accepted_foreign')->references(['id', 'help_topic_locale_id'])->on('help_topic_revisions')->restrictOnDelete();
            $table->index(['accepted_revision_id', 'help_topic_locale_id'], 'help_request_accepted_index');
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('model', 160);
            $table->string('response_model', 160)->nullable();
            $table->string('prompt_version', 100);
            $table->string('reasoning_effort', 32);
            $table->uuid('processing_token')->nullable();
            $table->string('response_id')->nullable();
            $table->string('request_id')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('help_content_exports', function (Blueprint $table): void {
            $table->boolean('requires_admin_authorization')->default(false);
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->uuid('processing_token')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('format_version')->default(1);
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('reason', 24);
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        $this->createIntegrityTriggers();
    }

    private function createIntegrityTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            $suffix = strtolower($event);
            $this->guard("help_locale_numbers_{$suffix}", 'help_topic_locales', $event, 'NEW.lock_version < 0');
            $this->guard("help_request_numbers_{$suffix}", 'help_translation_requests', $event, 'NEW.expected_target_lock_version < 0 OR NEW.input_tokens < 0 OR NEW.output_tokens < 0');
            $this->guard("help_export_numbers_{$suffix}", 'help_content_exports', $event, 'NEW.format_version < 1 OR NEW.size_bytes < 0');
        }

        $revisionFields = ['id', 'public_id', 'help_topic_locale_id', 'revision_number', 'title', 'summary', 'body_markdown', 'source_english_revision_id', 'origin', 'ai_model', 'prompt_version', 'created_at'];
        $this->guard('help_revision_immutable_update', 'help_topic_revisions', 'UPDATE', $this->changed($revisionFields).' OR ('.$this->changed(['created_by']).' AND NEW.created_by IS NOT NULL)');
        $this->guard('help_revision_immutable_delete', 'help_topic_revisions', 'DELETE', '1 = 1');
        $sourceExists = 'EXISTS (SELECT 1 FROM help_topic_revisions source JOIN help_topic_locales english ON english.id = source.help_topic_locale_id JOIN help_topic_locales target ON target.id = NEW.help_topic_locale_id WHERE source.id = NEW.source_english_revision_id AND english.locale = \'en\' AND english.help_topic_id = target.help_topic_id)';
        $this->guard('help_revision_source_insert', 'help_topic_revisions', 'INSERT', "NEW.revision_number < 1 OR EXISTS (SELECT 1 FROM help_topic_locales target WHERE target.id = NEW.help_topic_locale_id AND ((target.locale = 'en' AND NEW.source_english_revision_id IS NOT NULL) OR (target.locale <> 'en' AND NOT {$sourceExists})))");
        $this->guard('help_request_source_insert', 'help_translation_requests', 'INSERT', "NOT {$sourceExists} OR EXISTS (SELECT 1 FROM help_topic_locales target WHERE target.id = NEW.help_topic_locale_id AND target.locale = 'en')");
        $this->guard('help_topic_identity_update', 'help_topics', 'UPDATE', $this->changed(['id', 'public_id', 'key']).' OR ('.$this->changed(['domain']).' AND EXISTS (SELECT 1 FROM help_topic_locales locales JOIN help_topic_revisions revisions ON revisions.help_topic_locale_id = locales.id WHERE locales.help_topic_id = OLD.id))');
        $this->guard('help_locale_identity_update', 'help_topic_locales', 'UPDATE', '('.$this->changed(['id', 'help_topic_id', 'locale']).') AND (EXISTS (SELECT 1 FROM help_topic_revisions WHERE help_topic_locale_id = OLD.id) OR EXISTS (SELECT 1 FROM help_translation_requests WHERE help_topic_locale_id = OLD.id))');
        $this->guard('help_request_identity_update', 'help_translation_requests', 'UPDATE', $this->changed(['id', 'public_id', 'help_topic_locale_id', 'source_english_revision_id', 'expected_target_lock_version', 'model', 'prompt_version', 'reasoning_effort', 'created_at']).' OR ('.$this->changed(['requested_by']).' AND NEW.requested_by IS NOT NULL)');
    }

    /** @param list<string> $columns */
    private function changed(array $columns): string
    {
        $operator = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';

        return collect($columns)->map(fn (string $column): string => "NEW.{$column} {$operator} OLD.{$column}")->implode(' OR ');
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$name}'); END");

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $record = $event === 'DELETE' ? 'OLD' : 'NEW';
            DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$condition} THEN RAISE EXCEPTION '{$name}'; END IF; RETURN {$record}; END; \$\$");
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$name}()");

            return;
        }

        throw new RuntimeException('Contextual help integrity requires PostgreSQL or SQLite.');
    }

    public function down(): void
    {
        $triggers = [
            'help_revision_immutable_update' => 'help_topic_revisions',
            'help_revision_immutable_delete' => 'help_topic_revisions',
            'help_revision_source_insert' => 'help_topic_revisions',
            'help_request_source_insert' => 'help_translation_requests',
            'help_topic_identity_update' => 'help_topics',
            'help_locale_identity_update' => 'help_topic_locales',
            'help_request_identity_update' => 'help_translation_requests',
        ];
        foreach (['insert', 'update'] as $event) {
            $triggers["help_locale_numbers_{$event}"] = 'help_topic_locales';
            $triggers["help_request_numbers_{$event}"] = 'help_translation_requests';
            $triggers["help_export_numbers_{$event}"] = 'help_content_exports';
        }
        foreach ($triggers as $name => $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name} ON {$table}");
                DB::unprepared("DROP FUNCTION IF EXISTS {$name}()");
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('help_content_exports');
        Schema::dropIfExists('help_translation_requests');
        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds the locale table, so clear the cyclic rows being removed first.
            DB::table('help_topic_locales')->update(['latest_revision_id' => null, 'published_revision_id' => null]);
            DB::table('help_topic_revisions')->update(['source_english_revision_id' => null]);
            DB::table('help_topic_revisions')->delete();
        }

        Schema::table('help_topic_locales', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['latest_revision_id', 'id']);
                $table->dropForeign(['published_revision_id', 'id']);
            } else {
                $table->dropForeign('help_locale_latest_foreign');
                $table->dropForeign('help_locale_published_foreign');
            }
        });
        Schema::dropIfExists('help_topic_revisions');
        Schema::dropIfExists('help_topic_locales');
        Schema::dropIfExists('help_topics');
    }
};
