<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A line of the cash-flow forecast: a recurring charge or income, a one-off
     * the user expects, or a transfer between two of their accounts (when
     * `destination_account_id` is set) or out of the app (`destination_external`).
     * Its amount lives in forecast_item_versions, its one-month exceptions in
     * forecast_item_overrides; occurrences are always expanded on the fly.
     */
    public function up(): void
    {
        Schema::create('forecast_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('destination_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('destination_external')->default(false);
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->string('frequency');
            $table->date('anchor_date');
            $table->date('ends_on')->nullable();
            $table->string('source');
            $table->string('confidence');
            // JsonLogic in the AutomationRule::rules_json format.
            $table->json('match_rules_json')->nullable();
            $table->unsignedSmallInteger('date_window_days')->default(3);
            $table->bigInteger('amount_tolerance')->default(0);
            $table->string('fingerprint')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_items');
    }
};
