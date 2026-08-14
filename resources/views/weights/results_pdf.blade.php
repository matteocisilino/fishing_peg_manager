<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #212529;
        }
        h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }
        h2 {
            font-size: 16px;
            margin: 24px 0 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        th, td {
            border: 1px solid #ced4da;
            padding: 6px 8px;
            text-align: left;
        }
        thead th {
            background: #343a40;
            color: #fff;
        }
        tbody tr:nth-of-type(odd) {
            background: #f8f9fa;
        }
        tbody tr:nth-of-type(even) {
            background: #e9ecef;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            color: #fff;
        }
        .badge-primary {
            background: #0d6efd;
        }
        .badge-dark {
            background: #212529;
        }
        .meta {
            margin-bottom: 12px;
        }
        .meta span {
            margin-right: 12px;
        }
        .zone-block {
            page-break-inside: avoid;
        }
        .page-break {
            page-break-before: always;
        }
        .text-center {
            text-align: center;
        }
        .text-end {
            text-align: right;
        }
    </style>
</head>
<body>
    @php
        $formatPosition = static function ($value) {
            if ($value === null) {
                return '-';
            }
            return fmod($value, 1.0) === 0.0
                ? (string) (int) $value
                : number_format($value, 1, ',', '');
        };
        $dayEntries = $dayEntries ?? [];
        $tournamentStandings = $tournamentStandings ?? collect();
        $tournamentEvents = $tournamentEvents ?? collect();
        $hasTournamentStandings = $tournamentStandings->isNotEmpty();
        $hasDayEntries = !empty($dayEntries);
        $hasZoneTables = !empty($zones);
        $isTeamTournament = $event->tournament->isTeam();
    @endphp
    <h1>Event rankings</h1>
    <div class="meta">
        <span><strong>Tournament:</strong> {{ $event->tournament->name }}</span>
        <span><strong>Event:</strong> {{ $event->name }}</span>
        <span><strong>Date:</strong> {{ optional($event->event_date)->format('d/m/Y') ?? 'N/A' }}</span>
        <span><strong>Played events:</strong> {{ max($tournamentEvents->count(), 1) }}</span>
    </div>

    @if($hasTournamentStandings)
        <h2>General tournament ranking</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:60px;" class="text-center">Pos</th>
                    <th>Participant</th>
                    @if ($isTeamTournament)
                        <th>Team</th>
                    @endif
                    <th style="width:90px;" class="text-center">Penalties</th>
                    <th style="width:35px;" class="text-center">A</th>
                    <th style="width:110px;" class="text-center">Tot Penalties</th>
                    <th style="width:110px;" class="text-end">Total weight</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tournamentStandings as $row)
                    <tr>
                        <td class="text-center"><strong>{{ $row['position'] }}</strong></td>
                        <td>{{ $row['name'] }}</td>
                        @if ($isTeamTournament)
                            <td>{{ $row['team'] }}</td>
                        @endif
                        <td class="text-center">{{ $row['penalties_display'] }}</td>
                        <td class="text-center">{{ $row['has_absolute'] ? 'A' : '' }}</td>
                        <td class="text-center"><strong>{{ $row['total'] }}</strong></td>
                        <td class="text-end">{{ $row['weight_total_display'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($hasTournamentStandings && ($hasDayEntries || $hasZoneTables))
        <div class="page-break"></div>
    @endif

    @if($hasDayEntries)
        <h2>General day ranking</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:60px;" class="text-center">Pos</th>
                    <th>Participant</th>
                    @if($isTeamTournament)
                        <th>Team</th>
                    @endif
                    <th>Zone</th>
                    <th>Peg</th>
                    <th class="text-end">Weight</th>
                    <th>Zone ranking</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dayEntries as $entry)
                    <?php $row = $entry['row']; $draw = $row['draw']; ?>
                    <tr>
                        <td class="text-center"><strong>{{ $loop->iteration }}</strong></td>
                        <td>{{ $draw->frozen_angler_name ?? optional($draw->angler)->name ?? '—' }}</td>
                        @if($isTeamTournament)
                            <td>{{ $draw->frozen_team_name ?? optional(optional($draw->angler)->team)->name ?? '-' }}</td>
                        @endif
                        <td>{{ $entry['zone'] }}</td>
                        <td>{{ $draw->peg_number }}</td>
                        <td class="text-end">{{ number_format($row['weight'], 0, ',', '.') }}</td>
                        <td>
                            <?php $positionLabel = $formatPosition($row['position']); ?>
                            <span class="badge badge-primary">{{ $positionLabel }}</span>
                            @if ($row['is_absolute'])
                                <span class="badge badge-dark">A</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($hasZoneTables)
        @if($hasDayEntries)
            <div class="page-break"></div>
        @elseif($hasTournamentStandings)
            <div class="page-break"></div>
        @endif
        @foreach($zones as $zoneName => $entries)
            <div class="zone-block">
                <h2>Zone {{ $zoneName }}</h2>
                <p class="meta"><strong>Participants:</strong> {{ count($entries) }}</p>
                <table>
                    <thead>
                        <tr>
                            <th>Participant</th>
                            @if ($isTeamTournament)
                                <th>Team</th>
                            @endif
                            <th>Peg</th>
                            <th>Weight</th>
                            <th>Ranking</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $row)
                            <?php $draw = $row['draw']; ?>
                            <tr style="{{ ($row['placeholder'] ?? false) ? 'color:#6c757d;' : '' }}">
                                <td>
                                    @if($row['placeholder'] ?? false)
                                        <strong>T</strong> {{ $row['display_name'] ?? 'Ghost peg (empty)' }}
                                    @else
                                        {{ $draw->frozen_angler_name ?? optional($draw->angler)->name ?? '—' }}
                                    @endif
                                </td>
                                @if ($isTeamTournament)
                                    <td>
                                        @if($row['placeholder'] ?? false)
                                            —
                                        @else
                                            {{ $draw->frozen_team_name ?? optional(optional($draw->angler)->team)->name ?? '-' }}
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $row['peg_number'] ?? ($draw->peg_number ?? '—') }}</td>
                                <td>{{ $row['weight'] === null ? '—' : number_format($row['weight'], 0, ',', '.') }}</td>
                                <td>
                                    <?php $positionLabel = $formatPosition($row['position']); ?>
                                    <span class="badge badge-primary">{{ $positionLabel }}</span>
                                    @if (!( $row['placeholder'] ?? false) && ($row['is_absolute'] ?? false))
                                        <span class="badge badge-dark">A</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(!$loop->last)
                <div class="page-break"></div>
            @endif
        @endforeach
    @endif
</body>
</html>
