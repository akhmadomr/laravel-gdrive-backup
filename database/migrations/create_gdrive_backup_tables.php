<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gdrive_backup_settings')) {
            Schema::create('gdrive_backup_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('gdrive_backup_restore_histories')) {
            Schema::create('gdrive_backup_restore_histories', function (Blueprint $table) {
                $table->id();
                $table->string('file_name');
                $table->string('restored_by_name')->nullable();
                $table->string('restored_by_email')->nullable();
                $table->string('status', 20); // success | rejected | failed
                $table->string('reference', 20)->nullable()->index();
                $table->text('reason')->nullable(); // hanya untuk developer, tidak dikirim ke UI
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gdrive_backup_restore_histories');
        Schema::dropIfExists('gdrive_backup_settings');
    }
};
