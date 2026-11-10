<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription invoices whose charge failed, kept in step from Stripe's
 * webhooks so the dunning emails, their reminders and the in-app payment
 * banner never have to ask Stripe which invoices are still owed.
 *
 * A row is opened by the first `invoice.payment_failed` (or
 * `invoice.payment_action_required`) and closed by `resolved_at` once the
 * invoice is paid, voided, written off or its subscription ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('stripe_subscription_id')->index();
            $table->unsignedInteger('amount_due');
            $table->string('currency', 3);
            $table->string('billing_interval', 10)->nullable();
            $table->boolean('is_first_charge')->default(false);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('payment_intent_id')->nullable();
            $table->timestamp('first_failed_at');
            $table->timestamp('next_payment_attempt_at')->nullable();
            $table->timestamp('action_required_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_invoices');
    }
};
