<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The version of the widget rules (App\Services\Widgets\WidgetRules) a
     * widget's module was last checked against. Rows saved before it existed
     * were checked against the first rules: 1.
     */
    public function up(): void
    {
        Schema::table('widget_definitions', function (Blueprint $table) {
            $table->unsignedSmallInteger('rules_version')->default(1)->after('revision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_definitions', function (Blueprint $table) {
            $table->dropColumn('rules_version');
        });
    }
};
