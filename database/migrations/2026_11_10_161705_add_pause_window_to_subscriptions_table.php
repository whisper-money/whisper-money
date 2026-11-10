<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The window a paused subscription has no plan in. Stripe only knows that
 * collection is paused (`pause_collection`) and keeps the subscription
 * `active`, so Cashier still calls it subscribed: these two dates are what
 * `User::hasPausedSubscription()` reads to take the plan away meanwhile.
 *
 * A null `pause_resumes_at` with a start is a pause with no end, which only
 * Stripe's dashboard can set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('pause_starts_at')->nullable()->after('ends_at');
            $table->timestamp('pause_resumes_at')->nullable()->after('pause_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['pause_starts_at', 'pause_resumes_at']);
        });
    }
};
