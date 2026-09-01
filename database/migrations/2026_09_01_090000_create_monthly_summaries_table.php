<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One frozen snapshot per user, space and closed month.
     *
     * The payload is deliberately immutable: the email, the shareable card, the
     * history screen and the public page all read the same figures, so a report
     * about a closed month never changes under the reader's feet — not even when
     * they categorise an old transaction afterwards.
     */
    public function up(): void
    {
        Schema::create('monthly_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('space_id')->constrained()->cascadeOnDelete();
            // The closed month, as YYYY-MM.
            $table->char('period', 7);
            $table->json('payload');
            $table->text('ai_analysis')->nullable();
            $table->timestamp('ai_generated_at')->nullable();
            // Which shareable card was picked for this month.
            $table->string('card');
            // Whether every source had reported in when the snapshot was frozen.
            $table->boolean('complete')->default(false);
            // Minted the first time the user asks for a public link, never before.
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'space_id', 'period']);
            $table->index(['period', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_summaries');
    }
};
