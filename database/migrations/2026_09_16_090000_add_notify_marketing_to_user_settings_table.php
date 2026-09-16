<?php

use App\Enums\DripEmailType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The switch for product news and offers. Opt-out like every other notification
 * column: someone who signed up asked to hear from us, and the emails it covers
 * are the ones that explain the app to a reader still learning it. What it can
 * never silence is account, bank or billing mail, which is why the category is
 * a list in {@see DripEmailType::marketing()} rather than "everything
 * that is not operational".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('notify_marketing')->default(true)->after('notify_achievements');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('notify_marketing');
        });
    }
};
