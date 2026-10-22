<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            // Data-URL base64 (mis. "data:image/jpeg;base64,...") — ikut
            // payload CBT sehingga soal ber-gambar terbaca OFFLINE di HP.
            $table->text('gambar_soal')->nullable();
            // Link video eksternal (YouTube / URL file video) — streaming,
            // butuh internet, tidak dibawa dalam payload.
            $table->string('video_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->dropColumn(['gambar_soal', 'video_url']);
        });
    }
};