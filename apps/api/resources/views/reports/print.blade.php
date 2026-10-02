@php
    use App\Domain\Analytics\Format;
    use App\Domain\Reports\Export\SvgChart;
    $t = $theme; $interactive = $interactive ?? false;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $report->title }}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'DejaVu Sans', 'Inter', Arial, sans-serif; color: #{{ $t['text'] }}; background: #{{ $t['paper'] }}; font-size: 10.5pt; line-height: 1.5; }
  .cover { background: #{{ $t['ink'] }}; color: #fff; height: 297mm; padding: 96px 72px; position: relative; page-break-after: always; }
  .cover .org { color: #{{ $t['accent'] }}; letter-spacing: 3px; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
  .cover h1 { font-size: 34pt; line-height: 1.1; margin: 260px 0 12px; font-weight: bold; }
  .cover .sub { color: #C9CED8; font-size: 13pt; }
  .cover .rule { width: 96px; height: 4px; background: #{{ $t['accent'] }}; margin: 28px 0; }
  .cover .foot { position: absolute; bottom: 72px; left: 72px; right: 72px; color: #8B93A3; font-size: 8.5pt; }
  .page { padding: 56px 64px 40px; }
  section { margin-bottom: 30px; page-break-inside: avoid; }
  h2 { font-size: 15pt; color: #{{ $t['ink'] }}; margin: 0 0 4px; }
  h2:before { content: ''; display: block; width: 36px; height: 3px; background: #{{ $t['accent'] }}; margin-bottom: 10px; }
  .caption, .muted { color: #{{ $t['muted'] }}; font-size: 9pt; }
  .kpis { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px; }
  .kpi { background: #fff; border: 1px solid #E6E8EE; border-radius: 8px; padding: 12px 14px; vertical-align: top; width: 33%; }
  .kpi .l { font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; color: #{{ $t['muted'] }}; font-weight: bold; }
  .kpi .v { font-size: 19pt; font-weight: bold; color: #{{ $t['ink'] }}; margin: 4px 0 2px; }
  .pos { color: #{{ $t['positive'] }}; } .neg { color: #{{ $t['negative'] }}; } .neu { color: #{{ $t['muted'] }}; }
  .summary p { font-size: 11pt; margin: 0 0 8px; }
  table.data { width: 100%; border-collapse: collapse; font-size: 9pt; }
  table.data th { text-align: left; color: #{{ $t['muted'] }}; font-weight: bold; border-bottom: 1px solid #DADDE5; padding: 6px 4px; text-transform: uppercase; font-size: 7.5pt; letter-spacing: .5px; }
  table.data td { border-bottom: 1px solid #EEF0F4; padding: 6px 4px; }
  .risk { border-left: 3px solid #{{ $t['accent'] }}; padding: 6px 10px; margin-bottom: 8px; background: #fff; }
  .risk.high { border-color: #{{ $t['negative'] }}; }
  .tag { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
  .err { color: #{{ $t['negative'] }}; font-size: 9pt; }
  .provenance { border-top: 1px solid #DADDE5; padding-top: 10px; font-size: 7.5pt; color: #{{ $t['muted'] }}; }
  @media screen { body { max-width: 900px; margin: 0 auto; } .cover { height: auto; min-height: 520px; } .cover h1 { margin-top: 120px; } }
</style>
</head>
<body>
<div class="cover">
  <div class="org">{{ $report->organisation->name ?? '' }}</div>
  <h1>{{ $report->title }}</h1>
  <div class="sub">{{ $report->subtitle }} {{ isset($report->parameters['range_label']) ? '· '.$report->parameters['range_label'] : '' }}</div>
  <div class="rule"></div>
  <div class="foot">Generated {{ now()->format('j F Y, H:i') }} · Version {{ max(1, $report->current_version) }} · {{ ucfirst($report->status) }}<br>Every figure in this report is computed from governed semantic queries; query fingerprints are listed in the provenance section.</div>
</div>
<div class="page">
@foreach ($report->sections as $s)
  @php $c = $s->content; @endphp
  <section>
    <h2>{{ $s->title }}</h2>
    @if (isset($c['error']))
      <p class="err">{{ $c['error'] }}</p>
    @elseif ($s->type === 'summary')
      <div class="summary">@foreach ($c['paragraphs'] ?? [] as $p)<p>{{ $p }}</p>@endforeach</div>
    @elseif ($s->type === 'kpis')
      <div class="caption">{{ $c['period']['label'] ?? '' }} · compared with the previous period</div>
      <table class="kpis">
        @foreach (array_chunk($c['cards'] ?? [], 3) as $row)
          <tr>
          @foreach ($row as $k)
            <td class="kpi">
              <div class="l">{{ $k['label'] }}</div>
              <div class="v">{{ Format::value($k['value'], $k['format']) }}</div>
              <div class="{{ ['positive' => 'pos', 'negative' => 'neg'][$k['sentiment']] ?? 'neu' }}">{{ ['up' => '▲', 'down' => '▼'][$k['direction']] ?? '' }} {{ Format::change($k['change'], $k['change_pct'], $k['format']) }}</div>
              @if ($k['target'] !== null)<div class="muted">Target {{ Format::value($k['target'], $k['format']) }} · {{ $k['target_status'] }}</div>@endif
            </td>
          @endforeach
          </tr>
        @endforeach
      </table>
    @elseif ($s->type === 'chart')
      @if (!empty($c['caption']))<div class="caption">{{ $c['caption'] }}</div>@endif
      <img src="{{ SvgChart::dataUri(SvgChart::line($c['series'] ?? [], $c['format'], $t, 680, 220, ($c['chart'] ?? '') === 'area')) }}" width="680" alt="{{ $c['label'] }} trend">
    @elseif ($s->type === 'breakdown')
      <div class="caption">{{ $c['label'] }} by {{ $c['dimension_label'] }}</div>
      <img src="{{ SvgChart::dataUri(SvgChart::bars($c['rows'] ?? [], $c['format'], $t)) }}" width="680" alt="{{ $c['label'] }} by {{ $c['dimension_label'] }}">
    @elseif ($s->type === 'forecast')
      <div class="caption">{{ $c['label'] }} · method {{ str_replace('_', ' ', $c['method']) }} · shaded band is the 80% prediction interval</div>
      <img src="{{ SvgChart::dataUri(SvgChart::line($c['history'] ?? [], $c['format'], $t, 680, 220, false, $c['points'] ?? [])) }}" width="680" alt="{{ $c['label'] }} forecast">
    @elseif ($s->type === 'root_cause')
      <p><strong class="{{ ($c['sentiment'] ?? '') === 'negative' ? 'neg' : 'pos' }}">{{ $c['label'] }} {{ Format::change($c['change'] ?? null, $c['change_pct'] ?? null, $c['format']) }}</strong>
        <span class="muted">({{ Format::value($c['previous']['value'] ?? null, $c['format']) }} → {{ Format::value($c['current']['value'] ?? null, $c['format']) }})@if(!empty($c['onset']['date'])) · shift began around {{ date('j M Y', strtotime($c['onset']['date'])) }}@endif</span></p>
      <table class="data"><tr><th>Driver</th><th>Before</th><th>After</th><th>Share of change</th></tr>
        @foreach (array_slice($c['drivers'] ?? [], 0, 6) as $d)
          <tr><td>{{ $d['dimension_label'] }} · <strong>{{ $d['member'] }}</strong></td><td>{{ Format::value($d['previous_value'], $c['format']) }}</td><td>{{ Format::value($d['current_value'], $c['format']) }}</td><td>{{ round(($d['impact_share'] ?? 0) * 100) }}%</td></tr>
        @endforeach
      </table>
    @elseif ($s->type === 'anomalies')
      @if (empty($c['items']))<p class="muted">{{ $c['empty_message'] }}</p>@else
      <table class="data"><tr><th>Metric</th><th>Date</th><th>Expected</th><th>Actual</th><th>Deviation</th></tr>
        @foreach ($c['items'] as $a)
          <tr><td>{{ $a['label'] }}</td><td>{{ date('j M Y', strtotime($a['period'])) }}</td><td>{{ Format::value($a['expected'], $a['format']) }}</td><td><strong>{{ Format::value($a['actual'], $a['format']) }}</strong></td><td>{{ number_format(abs($a['score']), 1) }}σ {{ $a['direction'] }}</td></tr>
        @endforeach
      </table>@endif
    @elseif ($s->type === 'risks')
      @forelse ($c['items'] ?? [] as $r)
        <div class="risk {{ $r['severity'] }}"><span class="tag">{{ $r['severity'] }}</span> <strong>{{ $r['title'] }}</strong><br><span class="muted">{{ $r['detail'] }}</span></div>
      @empty <p class="muted">{{ $c['empty_message'] ?? '' }}</p>
      @endforelse
    @elseif ($s->type === 'text')
      @foreach (preg_split('/\n\n+/', $c['markdown'] ?? '') as $p)<p>{{ $p }}</p>@endforeach
    @endif
  </section>
@endforeach
  <div class="provenance">
    <strong>Provenance.</strong> Query fingerprints (SHA-256, first 12 chars):
    @foreach ($report->sections as $s)@foreach (($s->content['evidence'] ?? []) as $e){{ substr($e['query_hash'] ?? '', 0, 12) }} @endforeach @endforeach
  </div>
</div>
</body>
</html>
