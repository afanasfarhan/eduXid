<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if(!Schema::hasTable('broadcast_inbox_cache'))Schema::create('broadcast_inbox_cache',function(Blueprint $table){
            $table->id();$table->string('device_id');$table->string('message_id');
            $table->string('title',160);$table->text('body');$table->string('sender_type',40);
            $table->string('priority',20)->default('normal');$table->string('issued_at',40);
            $table->timestamp('read_at')->nullable();$table->timestamps();
            $table->unique(['device_id','message_id'],'broadcast_cache_device_msg_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('broadcast_inbox_cache'); }
};
