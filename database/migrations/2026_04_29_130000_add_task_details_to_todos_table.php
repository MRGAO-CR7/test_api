<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| todos.task_details — long-form description for a todo
|--------------------------------------------------------------------------
|
| Notes:
|
|   - Nullable on purpose: a todo is meaningful with just a `task_name`,
|     and forcing details on existing rows would either require a backfill
|     migration or a placeholder we'd then have to filter out at read time.
|   - Stored as `text`, not `string(...)`, because there's no useful upper
|     bound at the column level. The application-layer cap (form-request
|     `max:5000`) is what protects the database from a runaway payload.
|   - Placed after `task_name` for readability when inspecting the schema
|     directly; column order is otherwise irrelevant to the application.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->text('task_details')->nullable()->after('task_name');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropColumn('task_details');
        });
    }
};
