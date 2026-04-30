<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Todo\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a Todo in API responses.
 *
 * Notes for callers / future maintainers:
 *   - Timestamps are emitted as ISO-8601. The frontend already parses that
 *     directly into `Date` objects via its existing axios layer.
 *   - We never expose `deleted_at`. Soft-deleted rows are simply absent
 *     from the API surface; callers should not be able to tell the
 *     difference between a hard-deleted and a soft-deleted row.
 *
 * @mixin Todo
 */
final class TodoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_name' => $this->task_name,
            'task_details' => $this->task_details,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
