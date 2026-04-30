<?php

declare(strict_types=1);

use App\Domain\Todo\Models\Todo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| todos — task list aggregate
|--------------------------------------------------------------------------
|
| Notes for future migrations:
|
|   - This is intentionally a "global" todo list in this phase: there is no
|     `user_id` foreign key. Every authenticated caller sees and edits the
|     same set of rows. If/when ownership lands, add a nullable `user_id`
|     in a follow-up migration, backfill, then NOT NULL + FK in a third.
|   - `status` is a short string column, not an enum-typed column, so we can
|     extend the allowed set without an ALTER. The allowed values are
|     enforced at the application layer (see Todo::STATUSES + the
|     StoreTodoRequest / UpdateTodoRequest rule sets).
|   - Soft-deletes are on so a destructive call from the SPA is recoverable.
|     The repository / service layer never returns soft-deleted rows.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('task_name', 200);
            $table->string('status', 32)->default(Todo::STATUS_TODO);

            $table->softDeletes();
            $table->timestamps();

            // Hot path is "list todos filtered/sorted by status"; index it.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
