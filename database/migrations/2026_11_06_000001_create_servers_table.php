<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('connection_type', 20)->default('ssh');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username')->default('root');
            $table->string('auth_method', 20)->default('key_file');
            $table->string('key_path')->nullable();
            // Secrets are stored encrypted with APP_KEY (model casts).
            $table->text('private_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->text('password')->nullable();
            // SHA256 fingerprint of the server's host key, saved on the first connection.
            $table->string('host_fingerprint')->nullable();
            $table->string('status', 20)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('info')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
