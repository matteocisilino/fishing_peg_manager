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
            margin-bottom: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border: 1px solid #ced4da;
            padding: 6px 8px;
            text-align: left;
            font-size: 13px;
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
        .meta {
            font-size: 12px;
        }
        .meta span {
            margin-right: 10px;
        }
        ul {
            margin: 6px 0 0;
            padding: 0;
            list-style: none;
        }
        ul li {
            display: inline-block;
            margin-right: 10px;
            font-size: 11px;
            color: #6c757d;
        }
        .position-col {
            width: 55px;
            text-align: center;
            font-weight: 700;
        }
        .penalties-col {
            width: 80px;
            text-align: center;
        }
        .absolute-col {
            width: 35px;
            text-align: center;
            font-weight: 700;
        }
        .penalties-total-col {
            width: 90px;
            text-align: center;
            font-weight: 700;
        }
        .weight-total-col {
            width: 95px;
            text-align: right;
            white-space: nowrap;
        }
        .participant-col {
            width: 40%;
        }
    </style>
</head>
<body>
    <h1>Final results - {{ $tournament->name }}</h1>
    <div class="meta">
        <span><strong>Events:</strong> {{ $events->count() }}</span>
        <span><strong>Export date:</strong> {{ now()->format('d/m/Y H:i') }}</span>
    </div>
    @if($events->isNotEmpty())
        <ul>
            @foreach($events as $event)
                <li>{{ $event->name }} ({{ optional($event->event_date)->format('d/m/Y') ?? 'N/A' }})</li>
            @endforeach
        </ul>
    @endif

    <table>
        <thead>
            <tr>
                <th class="position-col">Pos</th>
                <th class="participant-col">Participant</th>
                @if($tournament->isTeam())
                    <th>Team</th>
                @endif
                <th class="penalties-col">Penalties</th>
                <th class="absolute-col"></th>
                <th class="penalties-total-col">Tot Penalties</th>
                <th class="weight-total-col">Total weight</th>
            </tr>
        </thead>
        <tbody>
            @foreach($standings as $row)
                <tr>
                    <td class="position-col"><strong>{{ $row['position'] }}</strong></td>
                    <td class="participant-col">{{ $row['name'] }}</td>
                    @if($tournament->isTeam())
                        <td>{{ $row['team'] }}</td>
                    @endif
                    <td class="penalties-col">{{ $row['penalties_display'] }}</td>
                    <td class="absolute-col">{{ $row['has_absolute'] ? 'A' : '' }}</td>
                    <td class="penalties-total-col"><strong>{{ $row['total'] }}</strong></td>
                    <td class="weight-total-col">{{ $row['weight_total_display'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
