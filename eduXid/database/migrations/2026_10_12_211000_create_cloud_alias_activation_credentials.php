<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('cloud_alias_activation_credentials')) return;
        Schema::create('cloud_alias_activation_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 8);
            $table->string('role', 12);
            $table->char('local_digest', 64);
            $table->string('cloud_alias', 32);
            $table->char('password_hash', 64);
            $table->text('encrypted_password')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id','role','local_digest'],'v21_activate_local_unique');
            $table->unique(['school_id','role','cloud_alias'],'v21_activate_alias_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('cloud_alias_activation_credentials'); }
};
