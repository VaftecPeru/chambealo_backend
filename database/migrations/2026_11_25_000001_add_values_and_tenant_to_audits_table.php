<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audits')) {
            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            if (!Schema::hasColumn('audits', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('audit_id')->index();
            }

            if (!Schema::hasColumn('audits', 'old_values')) {
                $table->json('old_values')->nullable()->after('action');
            }

            if (!Schema::hasColumn('audits', 'new_values')) {
                $table->json('new_values')->nullable()->after('old_values');
            }

            $table->index(['entity_type', 'entity_id'], 'audits_entity_type_entity_id_index');
            $table->index(['tenant_id', 'timestamp'], 'audits_tenant_id_timestamp_index');
            $table->index(['action', 'timestamp'], 'audits_action_timestamp_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('audits')) {
            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('audits_entity_type_entity_id_index');
            $table->dropIndex('audits_tenant_id_timestamp_index');
            $table->dropIndex('audits_action_timestamp_index');

            if (Schema::hasColumn('audits', 'new_values')) {
                $table->dropColumn('new_values');
            }

            if (Schema::hasColumn('audits', 'old_values')) {
                $table->dropColumn('old_values');
            }

            if (Schema::hasColumn('audits', 'tenant_id')) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
