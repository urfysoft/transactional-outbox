<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Urfysoft\TransactionalOutbox\Enums\InboxMessageStatusEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();

            $table->uuid('message_id')->unique()->index();
            $table->string('source_service')->index();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processes_at')->nullable()->index();
            $table->tinyInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->enum('status', InboxMessageStatusEnum::values())->default(InboxMessageStatusEnum::PENDING->value)->index();

            $table->index(['status', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
