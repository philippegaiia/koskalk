<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_translation_requests', function (Blueprint $table): void {
            $table->boolean('publish_automatically')->default(false);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER help_request_publication_intent_update BEFORE UPDATE ON help_translation_requests FOR EACH ROW WHEN NEW.publish_automatically IS NOT OLD.publish_automatically BEGIN SELECT RAISE(ABORT, 'help_request_publication_intent_update'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION help_request_publication_intent_update() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.publish_automatically IS DISTINCT FROM OLD.publish_automatically THEN RAISE EXCEPTION 'help_request_publication_intent_update'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER help_request_publication_intent_update BEFORE UPDATE ON help_translation_requests FOR EACH ROW EXECUTE FUNCTION help_request_publication_intent_update()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS help_request_publication_intent_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS help_request_publication_intent_update ON help_translation_requests');
            DB::unprepared('DROP FUNCTION IF EXISTS help_request_publication_intent_update()');
        }

        Schema::table('help_translation_requests', function (Blueprint $table): void {
            $table->dropColumn('publish_automatically');
        });
    }
};
