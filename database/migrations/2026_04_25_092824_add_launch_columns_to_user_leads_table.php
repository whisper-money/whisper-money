<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_leads', function (Blueprint $table): void {
            $table->string('cohort', 32)->nullable()->after('locale');
            $table->string('promo_code_monthly', 32)->nullable()->after('cohort');
            $table->string('promo_code_yearly', 32)->nullable()->after('promo_code_monthly');
            $table->timestamp('invitation_sent_at')->nullable()->after('promo_code_yearly');

            $table->index('cohort');
            $table->index('invitation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_leads', function (Blueprint $table): void {
            $table->dropIndex(['cohort']);
            $table->dropIndex(['invitation_sent_at']);
            $table->dropColumn([
                'cohort',
                'promo_code_monthly',
                'promo_code_yearly',
                'invitation_sent_at',
            ]);
        });
    }
};
