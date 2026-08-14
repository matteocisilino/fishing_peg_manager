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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Tournament extends Model
{
    public const TYPE_TEAM = 'team';
    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_POOL = 'pool';

    protected $fillable = ['name', 'sector_size', 'tournament_type', 'team_size', 'pool_capacity', 'allow_edge_repeats', 'structure_id'];

    protected $casts = [
        'sector_size' => 'int',
        'team_size' => 'int',
        'pool_capacity' => 'int',
        'allow_edge_repeats' => 'bool',
    ];

    /**
     * @return HasMany<Zone>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    /**
     * @return HasMany<Team>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<PoolPeg>
     */
    public function poolPegs(): HasMany
    {
        return $this->hasMany(PoolPeg::class);
    }

    /**
     * @return HasMany<FishingEvent>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FishingEvent::class);
    }

    /**
     * @return HasManyThrough<Angler, Team>
     */
    public function anglers(): HasManyThrough
    {
        return $this->hasManyThrough(Angler::class, Team::class);
    }

    /**
     * @return HasMany<WeightEntry>
     */
    public function weightEntries(): HasMany
    {
        return $this->hasMany(WeightEntry::class);
    }

    public function isIndividual(): bool
    {
        return $this->tournament_type === self::TYPE_INDIVIDUAL;
    }

    public function isTeam(): bool
    {
        return $this->tournament_type === self::TYPE_TEAM;
    }

    public function isPool(): bool
    {
        return $this->tournament_type === self::TYPE_POOL;
    }

    public function allowsEdgeRepeats(): bool
    {
        return (bool) $this->allow_edge_repeats;
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /**
     * @return BelongsToMany<StructureLake>
     */
    public function lakes(): BelongsToMany
    {
        return $this->belongsToMany(StructureLake::class, 'structure_lake_tournament')
            ->withTimestamps();
    }

    public function isCompleted(): bool
    {
        $events = $this->relationLoaded('events')
            ? $this->events
            : $this->events()->with(['draws', 'weightEntries'])->get();

        if ($events->isEmpty()) {
            return false;
        }

        foreach ($events as $event) {
            $draws = $event->relationLoaded('draws') ? $event->draws : $event->draws()->get();
            $weights = $event->relationLoaded('weightEntries')
                ? $event->weightEntries
                : $event->weightEntries()->get();

            if ($draws->count() === 0) {
                return false;
            }

            if ($weights->count() < $draws->count()) {
                return false;
            }
        }

        return true;
    }
}
