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

class MeteosatReport extends Model
{
    protected $fillable = [
        'fishing_event_id',
        'tournament_id',
        'structure_id',
        'comune',
        'start_at',
        'end_at',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(FishingEvent::class, 'fishing_event_id');
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }
}
