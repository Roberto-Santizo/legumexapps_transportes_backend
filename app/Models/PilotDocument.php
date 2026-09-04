<?php

namespace App\Models;

use Database\Factories\PilotDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The DPI and license photos a pilot uploads while registering.
 *
 * One row per pilot at most, and the row is all-or-nothing: both columns are NOT NULL,
 * so there is no half-filled record with a DPI and no license. The row itself is
 * optional — pilots registered before SPEC 25 simply do not have one, and nothing in
 * the application blocks them for it.
 *
 * It carries no `registered_by`: the author is the `user_id` itself, and at signup time
 * that user is not even authenticated yet. No `status`, no `deleted_at`, no `verified_at`
 * either — the documents exist or they do not, and nobody reviews them.
 *
 * The columns hold the full storage key («pilot-documents/{uuid}.jpg»), never the URL,
 * exactly like `vehicles.image` and `vehicle_expenses.invoice`.
 */
#[Fillable(['user_id', 'dpi_image', 'license_image'])]
class PilotDocument extends Model
{
    /** @use HasFactory<PilotDocumentFactory> */
    use HasFactory;

    /**
     * The pilot these documents belong to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
