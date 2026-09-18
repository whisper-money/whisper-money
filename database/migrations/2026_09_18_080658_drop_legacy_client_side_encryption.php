<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire the client-side encryption scheme: an AES key derived in the
     * browser from a per-user password, with the ciphertext's iv kept next to
     * each encrypted column. The key never reached the server, so anything
     * still encrypted at rest here is unreadable by anyone but a browser that
     * still had the key — which no release has shipped for months.
     *
     * The rows are therefore deleted, not decrypted. **This is irreversible**:
     * `down()` puts the columns and the table back empty, and no amount of
     * rolling back brings the data itself back.
     *
     * Dependants come off through the existing `ON DELETE CASCADE` foreign
     * keys: `account_balances`, `account_import_configs`, `real_estate_details`
     * and `loan_details` off `accounts`, and `label_transaction`,
     * `budget_transactions`, `category_corrections` and split parts
     * (`transactions.split_parent_id`) off `transactions`. The one account
     * reference that does not cascade, `real_estate_details.linked_loan_account_id`,
     * is `ON DELETE SET NULL` and so leaves no orphan either.
     */
    public function up(): void
    {
        // Ciphertext in `description`: the row says nothing about what was
        // spent or where, so there is nothing to keep. `DB::table()` bypasses
        // SoftDeletes on purpose — soft-deleted rows are just as unreadable.
        DB::table('transactions')->whereNotNull('description_iv')->delete();

        // A plaintext description with encrypted notes: the row is worth
        // keeping, only the unreadable note goes.
        DB::table('transactions')->whereNotNull('notes_iv')->update(['notes' => null]);

        // An account whose name is ciphertext takes its transactions with it.
        DB::table('transactions')
            ->whereIn('account_id', DB::table('accounts')->select('id')->whereNotNull('name_iv'))
            ->delete();
        DB::table('accounts')->whereNotNull('name_iv')->delete();

        Schema::dropIfExists('encrypted_messages');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('encryption_salt');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['name_iv', 'encrypted']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['description_iv', 'notes_iv']);
        });

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropColumn('action_note_iv');
        });
    }

    /**
     * Put the schema back, empty. The encrypted data `up()` deleted is gone for
     * good; this only makes the columns exist again.
     */
    public function down(): void
    {
        Schema::create('encrypted_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->text('encrypted_content');
            $table->string('iv', 16);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('encryption_salt', 24)->nullable()->after('remember_token');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('name_iv', 16)->nullable()->after('name');
            $table->boolean('encrypted')->default(true)->after('name_iv');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('description_iv', 16)->nullable()->after('description');
            $table->string('notes_iv', 16)->nullable()->after('notes');
        });

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('action_note_iv')->nullable()->after('action_note');
        });
    }
};
