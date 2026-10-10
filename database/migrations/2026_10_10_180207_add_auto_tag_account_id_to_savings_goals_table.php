<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The savings account a monthly goal's auto-tag rule watches. Rules stop at
     * the first match, so an account can feed only one running goal; keeping
     * the account on the goal is what lets that be checked.
     */
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->foreignUuid('auto_tag_account_id')->nullable()->after('notify_on_month_end_reminder')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_tag_account_id');
        });
    }
};
