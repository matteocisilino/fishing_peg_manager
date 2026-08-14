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

namespace App\Observers;

use App\Models\Angler;
use App\Models\Structure;
use App\Services\ParticipantDirectoryService;

class AnglerObserver
{
    public function __construct(private readonly ParticipantDirectoryService $directoryService)
    {
    }

    public function saved(Angler $angler): void
    {
        $structure = $this->resolveStructure($angler);
        $this->directoryService->registerName($angler->name, $structure);
    }

    private function resolveStructure(Angler $angler): ?Structure
    {
        $angler->loadMissing('team.tournament.structure');

        return $angler->team?->tournament?->structure;
    }
}
