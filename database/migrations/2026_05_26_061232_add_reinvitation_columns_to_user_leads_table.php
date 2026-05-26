<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_leads', function (Blueprint $table): void {
            $table->timestamp('re_invitation_sent_at')->nullable()->after('invitation_sent_at');
            $table->unsignedInteger('re_invitation_count')->default(0)->after('re_invitation_sent_at');

            $table->index('re_invitation_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_leads', function (Blueprint $table): void {
            $table->dropIndex(['re_invitation_sent_at']);
            $table->dropColumn(['re_invitation_sent_at', 're_invitation_count']);
        });
    }
};
