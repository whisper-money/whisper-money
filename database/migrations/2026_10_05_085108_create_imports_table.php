<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A full import from another app, and the rows it is still waiting to write.
     *
     * The browser parses the file and uploads the result in chunks, because a
     * decade of movements does not fit in one request. The chunks sit in
     * `import_chunks` until the queued job has written them, and are deleted
     * then: the import row keeps only the counts, the plan is gone with them.
     */
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('file_name')->nullable();
            $table->string('mode');
            $table->string('status');
            $table->json('plan')->nullable();
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('import_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('position');
            $table->unsignedInteger('row_count');
            $table->longText('rows');
            $table->timestamps();

            $table->unique(['import_id', 'kind', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_chunks');
        Schema::dropIfExists('imports');
    }
};
