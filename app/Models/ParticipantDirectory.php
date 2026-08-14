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

class ParticipantDirectory extends Model
{
    protected $table = 'participant_directory';

    protected $fillable = [
        'structure_id',
        'name',
        'phone',
        'province',
        'last_known_name',
        'anonymized_code',
        'anonymized_at',
    ];

    protected $casts = [
        'anonymized_at' => 'datetime',
    ];

    public function structure()
    {
        return $this->belongsTo(Structure::class);
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }
}
