<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('cloud_identity_alias_routes')) return;
        Schema::create('cloud_identity_alias_routes', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 8);
            $table->string('role', 12);
            $table->char('local_digest', 64);
            $table->string('cloud_alias', 32);
            $table->timestamps();
            $table->unique(['school_id','role','local_digest'],'opaque_local_identity_unique');
            $table->unique(['school_id','role','cloud_alias'],'opaque_cloud_alias_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('cloud_identity_alias_routes'); }
};
