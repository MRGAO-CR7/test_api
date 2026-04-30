<?php

declare(strict_types=1);

namespace App\Domain\Todo\Models;

use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single task on the global todo list.
 *
 * Status lifecycle (enforced at the application layer, not the DB):
 *
 *   todo  ──▶  scheduled  ──▶  done
 *     ▲                          │
 *     └──────── re-open ─────────┘
 *
 * The set of allowed values lives on this class as a single source of
 * truth -- the migration default, the form-request rule sets, and the
 * factory states all read from it. Adding a status is a one-line change
 * here plus opt-in adoption at the call sites.
 *
 * @property int $id
 * @property string $task_name
 * @property string|null $task_details
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_TODO = 'todo';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_DONE = 'done';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_SCHEDULED,
        self::STATUS_DONE,
    ];

    protected $table = 'todos';

    /** @var list<string> */
    protected $fillable = [
        'task_name',
        'task_details',
        'status',
    ];

    /**
     * Hide nothing in resource serialization -- TodoResource is the canonical
     * shape for the API. Anything that should not be exposed simply does not
     * appear in the resource.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * Override the factory resolver because the model lives outside the
     * conventional `App\Models` namespace.
     */
    protected static function newFactory(): TodoFactory
    {
        return TodoFactory::new();
    }
}
