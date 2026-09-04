<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * Since SPEC 24 something does hang off a client: `trips.client_id`, which is why the
 * service refuses to delete one that still has trips.
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
     * The export trips shipped for this client.
     *
     * The only relation this catalog owns, added by SPEC 24. Its single reader is the
     * guard in the service, which counts them —deleted ones included— before allowing a
     * deletion: a soft deleted client would vanish from the API while its trips kept
     * showing the name of somebody nobody can look up any more.
     *
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
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
