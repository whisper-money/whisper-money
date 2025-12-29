<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('banks', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['bank_id']);
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['account_id']);
                $table->dropForeign(['category_id']);
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['action_category_id']);
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->dropForeign(['account_id']);
            });

            DB::statement('DELETE FROM users');
            DB::statement('DELETE FROM banks');
            DB::statement('DELETE FROM accounts');
            DB::statement('DELETE FROM categories');
            DB::statement('DELETE FROM transactions');
            DB::statement('DELETE FROM automation_rules');
            DB::statement('DELETE FROM encrypted_messages');
            DB::statement('DELETE FROM account_balances');

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('id');
            });
            Schema::table('users', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
            });

            Schema::table('banks', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('banks', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
                $table->uuid('user_id')->nullable()->after('id');
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
                $table->dropColumn('bank_id');
            });
            Schema::table('accounts', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
                $table->uuid('user_id')->after('id');
                $table->uuid('bank_id')->after('name_iv');
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('categories', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
                $table->uuid('user_id')->after('color');
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('user_id');
                $table->dropColumn('account_id');
                $table->dropColumn('category_id');
            });
            Schema::table('transactions', function (Blueprint $table) {
                $table->uuid('user_id')->after('id');
                $table->uuid('account_id')->after('user_id');
                $table->uuid('category_id')->nullable()->after('account_id');
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
                $table->dropColumn('action_category_id');
            });
            Schema::table('automation_rules', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
                $table->uuid('user_id')->after('id');
                $table->uuid('action_category_id')->nullable()->after('rules_json');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->uuid('id')->primary()->first();
                $table->uuid('user_id')->after('id');
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->dropUnique('account_balances_account_id_balance_date_unique');
                $table->dropColumn('account_id');
            });
            Schema::table('account_balances', function (Blueprint $table) {
                $table->uuid('account_id')->after('id');
            });

            Schema::table('sessions', function (Blueprint $table) {
                $table->uuid('user_id')->nullable()->after('id')->index();
            });

            Schema::table('banks', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('bank_id')->references('id')->on('banks')->onDelete('cascade');
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('action_category_id')->references('id')->on('categories')->onDelete('set null');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            });
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['bank_id']);
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['account_id']);
                $table->dropForeign(['category_id']);
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['action_category_id']);
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->dropForeign(['account_id']);
            });

            DB::statement('DELETE FROM users');
            DB::statement('DELETE FROM banks');
            DB::statement('DELETE FROM accounts');
            DB::statement('DELETE FROM categories');
            DB::statement('DELETE FROM transactions');
            DB::statement('DELETE FROM automation_rules');
            DB::statement('DELETE FROM encrypted_messages');
            DB::statement('DELETE FROM account_balances');

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('id');
            });
            Schema::table('users', function (Blueprint $table) {
                $table->id()->first();
            });

            Schema::table('banks', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('banks', function (Blueprint $table) {
                $table->id()->first();
                $table->foreignId('user_id')->nullable()->after('id');
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
                $table->dropColumn('bank_id');
            });
            Schema::table('accounts', function (Blueprint $table) {
                $table->id()->first();
                $table->foreignId('user_id')->after('id');
                $table->foreignId('bank_id')->after('name_iv');
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('categories', function (Blueprint $table) {
                $table->id()->first();
                $table->foreignId('user_id')->after('color');
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('user_id');
                $table->dropColumn('account_id');
                $table->dropColumn('category_id');
            });
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreignId('user_id')->after('id');
                $table->foreignId('account_id')->after('user_id');
                $table->foreignId('category_id')->nullable()->after('account_id');
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
                $table->dropColumn('action_category_id');
            });
            Schema::table('automation_rules', function (Blueprint $table) {
                $table->id()->first();
                $table->foreignId('user_id')->after('id');
                $table->foreignId('action_category_id')->nullable()->after('rules_json');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->dropColumn('id');
                $table->dropColumn('user_id');
            });
            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->id()->first();
                $table->foreignId('user_id')->after('id');
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->dropColumn('account_id');
            });
            Schema::table('account_balances', function (Blueprint $table) {
                $table->foreignId('account_id')->after('id');
            });

            Schema::table('sessions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->index();
            });

            Schema::table('banks', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('accounts', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('bank_id')->references('id')->on('banks')->onDelete('cascade');
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            });

            Schema::table('automation_rules', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('action_category_id')->references('id')->on('categories')->onDelete('set null');
            });

            Schema::table('encrypted_messages', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });

            Schema::table('account_balances', function (Blueprint $table) {
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            });
        });
    }
};
