<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order of Play: {{ $sheet['session']['name'] }}</title>
<style>
  @page { margin: 12mm 10mm; }
  body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; font-size: 9pt; color: #1b1f2a; margin: 0; }
  h1 { font-size: 15pt; margin: 0 0 2px; }
  h1 span { font-size: 9pt; font-weight: normal; color: #555; }
  .meta { font-size: 8pt; color: #555; margin: 0 0 8px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #1c2b5a; color: #fff; font-size: 8pt; padding: 4px 3px; text-align: left; }
  td { border: 1px solid #c9ced8; padding: 3px 4px; vertical-align: middle; }
  td.c { text-align: center; }
  tr.M td { background: #e8f1fc; }
  tr.W td { background: #fde8f0; }
  tr.Mix td { background: #efe8fc; }
  tr.round-start td { border-top: 2px solid #1c2b5a; }
  /* After the row tints so the score and signature boxes stay white. */
  td.score { width: 26px; text-align: center; background: #ffffff; font-weight: bold; }
  td.paraf { width: 60px; background: #ffffff; }
  .gp-win { font-weight: bold; text-decoration: underline; }
  .toolbar { margin: 0 0 10px; font-size: 10pt; }
  @media print { .toolbar { display: none; } }
</style>
</head>
<body>
@unless ($pdf)
<div class="toolbar">
  <a href="?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}">Download PDF</a>
  · <a href="javascript:window.print()">Print</a>
</div>
@endunless

<h1>Order of Play: {{ $sheet['session']['name'] }} <span>· {{ $sheet['session']['scheduled_date'] }}</span></h1>
<p class="meta">{{ $sheet['session']['match_type'] }} · {{ $sheet['session']['court_count'] }} court · {{ $sheet['session']['format'] }}</p>

<table>
  <thead>
    <tr>
      <th>No</th>
      <th>Ronde</th>
      @if ($hasTime)<th>Jam</th>@endif
      <th>Court</th>
      <th>Kat.</th>
      <th>Tim A</th>
      <th colspan="2">Skor</th>
      <th>Tim B</th>
      <th>GP</th>
      <th>Paraf</th>
    </tr>
  </thead>
  <tbody>
  @php($previousRound = null)
  @foreach ($sheet['rows'] as $row)
    <tr class="{{ $row['category'] }} {{ $previousRound !== null && $previousRound !== $row['round'] ? 'round-start' : '' }}">
      <td class="c">{{ $row['no'] }}</td>
      <td class="c">{{ $row['round'] }}</td>
      @if ($hasTime)<td class="c">{{ $row['time'] }}</td>@endif
      <td class="c">C{{ $row['court'] }}</td>
      <td class="c">{{ $row['category'] }}</td>
      <td>{{ $row['team_a'] }}</td>
      <td class="score">{{ $row['games_a'] ?? '' }}</td>
      <td class="score">{{ $row['games_b'] ?? '' }}</td>
      <td>{{ $row['team_b'] }}</td>
      <td class="c">
        @if ($row['golden_point'])
          <span class="{{ $row['decided_by_golden_point'] && $row['winner'] === 'a' ? 'gp-win' : '' }}">A</span>
          / <span class="{{ $row['decided_by_golden_point'] && $row['winner'] === 'b' ? 'gp-win' : '' }}">B</span>
        @else
          –
        @endif
      </td>
      <td class="paraf"></td>
    </tr>
    @php($previousRound = $row['round'])
  @endforeach
  </tbody>
</table>
</body>
</html>
