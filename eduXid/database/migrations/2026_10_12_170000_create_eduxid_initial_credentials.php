<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('eduxid_initial_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->text('ciphertext');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('eduxid_initial_credentials');
    }
};
