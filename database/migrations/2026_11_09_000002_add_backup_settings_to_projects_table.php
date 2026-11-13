<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defaults for a small server: a database backup before the migrations of every
     * deploy, and only the newest one kept on the server (keep_db_backups 0 = none).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('backup_before_deploy')->default(true)->after('keep_releases');
            $table->unsignedTinyInteger('keep_db_backups')->default(1)->after('backup_before_deploy');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['backup_before_deploy', 'keep_db_backups']);
        });
    }
};
