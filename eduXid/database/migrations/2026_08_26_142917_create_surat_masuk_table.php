<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('surat_masuk', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_surat');
            $table->string('nomor_surat');
            $table->string('perihal');
            $table->date('tanggal_surat');
            $table->string('file_path')->nullable(); // Untuk menyimpan path PDF
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // User TU yang input
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('surat_masuk');
    }
};
