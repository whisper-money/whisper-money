<?php

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Cut loose the transactions still pointing at a soft-deleted category.
     *
     * Deleting a category with the promote or reparent strategy left its
     * transactions holding the dead id (only the cascade strategy uncategorized
     * them). Those rows then read differently depending on who was counting:
     * the dashboard's cashflow widget matched the category row regardless of its
     * `deleted_at` and booked them as spending, while the cashflow screen
     * resolves the relation - getting null - and dropped them from both the
     * totals and the category breakdown. Same month, two different numbers, and
     * the money invisible on the screen meant to explain it.
     *
     * 434 transactions across 11 users and 20 dead categories at the time of
     * writing. Uncategorized is what they already are everywhere the category is
     * read through the relation; this makes the column say so, which is also
     * what the delete does from now on.
     */
    public function up(): void
    {
        Transaction::withTrashed()
            ->whereIn('category_id', Category::onlyTrashed()->select('id'))
            ->update(['category_id' => null]);
    }

    public function down(): void
    {
        // One-off repair. The category these rows pointed at is deleted, so
        // there is nothing to point them back at.
    }
};
