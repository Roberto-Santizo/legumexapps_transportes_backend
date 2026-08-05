<?php

namespace App\Models;

use Database\Factories\CarrierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'name', 'image', 'code', 'active'])]
class Carrier extends Model
{
    /** @use HasFactory<CarrierFactory> */
    use HasFactory;

    /**
     * The user, with the carrier role, that owns this company.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The users, with the pilot role, linked to this company.
     *
     * @return BelongsToMany<User, $this>
     */
    public function pilots(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'carrier_pilots')->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
