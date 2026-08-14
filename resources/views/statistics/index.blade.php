@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Statistics</h1>

        <div class="card mb-4">
            <div class="card-header">Total points per peg</div>
            <div class="card-body">
                @if($pegTotals->isEmpty())
                    <div class="alert alert-info mb-0">No data available.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Venue</th>
                                    <th>Peg</th>
                                    <th>Total points</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pegTotals as $row)
                                    <tr>
                                        <td>{{ $row->structure_name ?? 'N/A' }}</td>
                                        <td>
                                            @if($row->structure_id)
                                                <a href="{{ route('statistics.pegs.show', [$row->structure_id, $row->peg_number]) }}">
                                                    {{ ($row->structure_name ?? 'N/A') . '.peg' . $row->peg_number }}
                                                </a>
                                            @else
                                                {{ 'N/A.peg' . $row->peg_number }}
                                            @endif
                                        </td>
                                        <td>{{ number_format($row->total_weight, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                <span>Total points per angler</span>
                <form method="get" action="{{ route('statistics.index') }}" class="mt-2 mt-lg-0">
                    <div class="input-group input-group-sm">
                        <input type="text"
                               name="angler_search"
                               class="form-control"
                               placeholder="Filter by name"
                               value="{{ $anglerSearch }}">
                        <button class="btn btn-outline-primary" type="submit">Filter</button>
                        <a href="{{ route('statistics.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
            <div class="card-body">
                @if($anglerTotals->isEmpty())
                    <div class="alert alert-info mb-0">No data available.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Angler</th>
                                    <th>Total points</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($anglerTotals as $row)
                                    <tr>
                                        <td>
                                            @if($row->angler)
                                                <a href="{{ route('statistics.anglers.show', $row->angler) }}">
                                                    {{ $row->angler->name }}
                                                </a>
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td>{{ number_format($row->total_weight, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
