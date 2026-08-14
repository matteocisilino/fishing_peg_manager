<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\EventZoneLock;
use App\Models\MeteosatJob;
use App\Models\MeteosatReport;
use App\Models\Structure;

class FishingEvent extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'tournament_id',
        'event_date',
        'name',
        'is_locked',
        'structure_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_locked' => 'boolean',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /**
     * @return HasMany<Draw>
     */
    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class, 'event_id');
    }

    /**
     * @return HasMany<WeightEntry>
     */
    public function weightEntries(): HasMany
    {
        return $this->hasMany(WeightEntry::class, 'event_id');
    }

    /**
     * @return HasMany<EventZoneLock>
     */
    public function zoneLocks(): HasMany
    {
        return $this->hasMany(EventZoneLock::class, 'event_id');
    }

    public function meteosatReport(): HasOne
    {
        return $this->hasOne(MeteosatReport::class, 'fishing_event_id');
    }

    public function meteosatJob(): HasOne
    {
        return $this->hasOne(MeteosatJob::class, 'fishing_event_id');
    }
}
