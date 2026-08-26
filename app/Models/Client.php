<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client of the national catalog: a code and a name, and nothing else.
 *
 * The smallest domain of the project, and the first catalog that deletes for real.
 * The other national catalogs go down with a boolean `status` and keep showing up in
 * the listing; here `DELETE` soft deletes the row, it disappears from the API and it
 * never comes back — while still holding on to its `code` and its `name`, which the
 * unique indexes keep reserved forever.
 *
 * Nothing hangs off a client yet: no table owns a `client_id`.
 */
#[Fillable(['code', 'name', 'registered_by'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The administrator that captured this client.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a client name: trim it, collapse inner whitespace and upper case it.
     *
     * A copy of the rule `Product`, `Location` and `Accessory` already own, not a call
     * to any of them: each catalog keeps its own normalization and may diverge without
     * dragging the others along.
     *
     * Shared by the form requests, which normalize before validating, and by the
     * service, which applies it right before checking uniqueness and persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * Normalize a client code: trim it and upper case it.
     *
     * It deliberately does **not** collapse inner whitespace, and it does not need to:
     * a code carrying any space at all never reaches the service, the form request
     * rejects it with 422. Silently turning `CLI 001` into `CLI001` would hide a typo
     * instead of reporting it.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
