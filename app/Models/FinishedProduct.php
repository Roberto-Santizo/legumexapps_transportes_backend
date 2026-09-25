<?php

namespace App\Models;

use Database\Factories\FinishedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A finished product SKU: a packed presentation that belongs to a client.
 *
 * Unrelated to `Product` (SPEC 07) — they share a word, not a domain. Like `Client`,
 * it soft deletes for real and a deleted row keeps its `code` reserved forever; unlike
 * every other catalog, its `name` is neither unique nor trimmed.
 */
#[Fillable(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id', 'registered_by'])]
class FinishedProduct extends Model
{
    /** @use HasFactory<FinishedProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presentation' => 'decimal:2',
            'boxes_per_pallet' => 'decimal:2',
        ];
    }

    /**
     * The client this SKU is packed for.
     *
     * Reads trashed clients on purpose: deleting a client does not block on its SKUs,
     * and those SKUs must keep showing the client's name.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * The user that captured this finished product.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a finished product code: trim it and upper case it.
     *
     * Inner whitespace is not collapsed: a code carrying any space never reaches the
     * service, the form request rejects it with 422.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Normalize a finished product name: upper case it and nothing else.
     *
     * Deliberately breaks with the `normalizeName()` of the other catalogs: no trim and
     * no whitespace collapsing, the name is stored as typed.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper($name);
    }
}
