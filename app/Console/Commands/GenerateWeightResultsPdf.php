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

namespace App\Console\Commands;

use App\Models\FishingEvent;
use App\Services\Weights\EventResultsPdfDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateWeightResultsPdf extends Command
{
    protected $signature = 'weights:results-pdf {event : ID della prova} {--path= : Percorso completo del file di output}';

    protected $description = 'Genera il PDF dei piazzamenti di una prova e lo salva su disco';

    public function __construct(
        private readonly EventResultsPdfDataBuilder $eventResultsPdfDataBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (int) $this->argument('event');

        /** @var FishingEvent|null $event */
        $event = FishingEvent::with([
            'tournament',
            'draws.angler.team',
            'draws.angler.zone',
            'weightEntries',
        ])->find($eventId);

        if (!$event) {
            $this->error('Prova non trovata.');
            return self::FAILURE;
        }

        $pdfData = $this->eventResultsPdfDataBuilder->build($event);
        if (!$pdfData['placement']['complete']) {
            $this->error('Impossibile generare il PDF: pesature non complete.');
            return self::FAILURE;
        }

        $path = $this->option('path') ?: storage_path(sprintf('app/piazzamenti-evento-%d.pdf', $event->id));
        $directory = dirname($path);
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $pdf = Pdf::loadView('weights.results_pdf', [
            'event' => $event,
            'zones' => $pdfData['zones'],
            'absoluteMax' => $pdfData['absolute_max'],
            'dayEntries' => $pdfData['day_entries'],
            'tournamentStandings' => $pdfData['tournament_standings'],
            'tournamentEvents' => $pdfData['tournament_events'],
        ])->setPaper('a4', 'landscape');

        $pdf->save($path);

        $this->info(sprintf('PDF generato: %s', $path));

        return self::SUCCESS;
    }
}
