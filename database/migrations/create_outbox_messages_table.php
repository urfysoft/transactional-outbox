<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Urfysoft\TransactionalOutbox\Enums\OutboxMessageStatusEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();

            $table->uuid('message_id')->unique()->index();
            $table->string('aggregate_type')->index();
            $table->string('aggregate_id')->index();
            $table->string('event_type')->index();
            $table->string('destination_service')->index();
            $table->string('destination_topic')->nullable();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('processes_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->tinyInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->enum('status', OutboxMessageStatusEnum::values())->default(OutboxMessageStatusEnum::PENDING->value)->index();

            $table->index(['status', 'created_at']);
            $table->index(['destination_service', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
