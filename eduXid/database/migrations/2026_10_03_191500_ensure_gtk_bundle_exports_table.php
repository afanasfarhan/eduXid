<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('gtk_bundle_exports')) {
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
            return;
        }

        Schema::table('gtk_bundle_exports', function (Blueprint $t) {
            if (!Schema::hasColumn('gtk_bundle_exports', 'school_id')) $t->string('school_id', 100)->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'teacher_id')) $t->unsignedBigInteger('teacher_id')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'login_id')) $t->string('login_id')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'device_id')) $t->string('device_id')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'device_token')) $t->text('device_token')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'status')) $t->string('status')->default('pending');
            if (!Schema::hasColumn('gtk_bundle_exports', 'archive_name')) $t->string('archive_name')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'sha256')) $t->string('sha256', 64)->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'size_bytes')) $t->unsignedBigInteger('size_bytes')->nullable();
            if (!Schema::hasColumn('gtk_bundle_exports', 'prepared_at')) $t->timestamp('prepared_at')->nullable();
        });
    }

    public function down(): void
    {
    }
};
