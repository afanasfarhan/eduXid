<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gtk_bundle_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('school_id', 100);
            $t->unsignedBigInteger('teacher_id');
            $t->string('login_id');
            $t->string('device_id');
            $t->text('device_token')->nullable();
            $t->string('status')->default('pending');
            $t->string('archive_name')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamp('prepared_at')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtk_bundle_exports');
    }
};
