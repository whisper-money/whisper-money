<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per run of the in-app cancel flow: why the user wanted to cancel,
 * the offer they were shown and how it ended. A row whose `outcome` is still
 * null is a flow the user walked away from, or left for one of the help
 * offers without cancelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The Stripe id rather than the local subscription: the local row
            // is deleted with the customer, the Stripe id still finds it there.
            $table->string('stripe_subscription_id');
            // `App\Enums\CancellationReason`.
            $table->string('reason', 64);
            $table->text('details')->nullable();
            // `App\Enums\CancellationOffer`: the reason-matched offer, if any.
            $table->string('offer_shown', 64)->nullable();
            // The offer that kept the plan (the discount or the yearly switch),
            // which may be the fallback. The help offers (roadmap, support)
            // do not end the flow and are only tracked in analytics.
            $table->string('offer_accepted', 64)->nullable();
            // `App\Enums\CancellationOutcome`; null while the flow is open.
            $table->string('outcome', 32)->nullable();
            $table->boolean('on_trial');
            $table->timestamps();

            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_survey_responses');
    }
};
