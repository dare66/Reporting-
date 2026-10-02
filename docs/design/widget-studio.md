# Widget Studio and dashboard filters: design

**Status:** implemented in the "Widget Studio" phase.
**Research behind it:** [`docs/research/bi-landscape.md`](../research/bi-landscape.md).

## Goals

1. **Build any chart on a dashboard without code.** Authors get a chart picker,
   a data panel, a design panel and a live preview, with the depth a Sisense,
   Power BI or Tableau user expects.
2. **Filter a dashboard by any dimension.** The filter types are members
   (include or exclude), text, numeric, ranking and measure thresholds.
3. **Never weaken the platform's guarantees.**
   - Every option compiles into the governed semantic query.
   - Row-level security applies to filter choices as well as to results.
   - Every number is traceable to its SQL.
   - The colour-blind-safe palette is preserved.

## Non-goals (and why)

- **Dual-axis charts.** Two y-scales on one plot suggest correlations that the
  scale choice created, so we offer small multiples or indexing to a common
  base instead. See research §4.
- **Ad hoc formulas inside a widget.** A formula typed into one widget forks the
  definition of a metric. New metrics are added to the semantic model, where
  they are versioned, permissioned and visible to the AI analyst.
- **Free hex colours.** Colours come from the validated categorical slots and
  the reserved status colours.

---

## 1. Query contract (API)

Everything a user builds is a `SemanticQuery`. This phase extends it
compatibly: every existing query stays valid.

```jsonc
{
  "model": "applications",
  "metrics": ["total_applications", "sla_compliance"],
  "dimensions": ["region", "channel"],          // the 1st is the category; the 2nd is "break by"
  "time": { "grain": "month", "range": "last_12_months" },
  "filters": [
    { "dimension": "country", "op": "not_in", "value": ["China"] },          // exclude members
    { "dimension": "institution", "op": "starts_with", "value": "Uni" },     // text
    { "dimension": "institution", "op": "top", "value": { "n": 10, "metric": "total_applications" } } // ranking
  ],
  "having": [ { "metric": "sla_compliance", "op": "lt", "value": 0.85 } ],    // measure filter
  "calculations": [ { "fn": "percent_of_total", "metric": "total_applications" } ], // quick functions
  "sort": [ { "key": "total_applications", "dir": "desc" } ],
  "limit": 50
}
```

### 1.1 Filter operators

| Family | Operators | Notes |
|---|---|---|
| Members | `in`, `not_in`, `eq`, `neq` | Up to 1,000 values. |
| Text | `contains`, `not_contains`, `starts_with`, `ends_with` | Case-insensitive. The value is bound and LIKE wildcards are escaped. |
| Numeric | `gt`, `gte`, `lt`, `lte`, `between`, `not_between` | `between` is inclusive. |
| Null | `is_null`, `not_null` | |
| Ranking | `top`, `bottom` with `{ n, metric }` | Resolved by a governed pre-query (§1.4). |

### 1.2 Measure filters: `having`

`[{ metric, op, value }]`, where `op` is one of `eq`, `neq`, `gt`, `gte`,
`lt`, `lte`, `between` or `not_between`. Each compiles to
`HAVING <metric expression> <op> ?`. The metric must be one the user is
allowed to query.

### 1.3 Quick functions: `calculations`

`[{ fn, metric, window? }]`. Each calculation adds a result column keyed
`<metric>__<fn>`, computed by a SQL window over the aggregated metric, so it
appears in the evidence SQL.

| `fn` | SQL | Requires | Format |
|---|---|---|---|
| `percent_of_total` | `m / NULLIF(SUM(m) OVER (PARTITION BY period), 0)` | additive metric | percent |
| `running_sum` | `SUM(m) OVER (PARTITION BY dims ORDER BY period)` | grain, additive metric | metric's format |
| `year_to_date` | `SUM(m) OVER (PARTITION BY dims, year(period) ORDER BY period)` | grain, additive metric | metric's format |
| `difference` | `m − LAG(m) OVER (PARTITION BY dims ORDER BY period)` | grain | metric's format |
| `percent_change` | `(m − LAG(m)) / NULLIF(ABS(LAG(m)), 0)` | grain | percent |
| `moving_average` | `AVG(m) OVER (… ROWS BETWEEN window−1 PRECEDING AND CURRENT ROW)`; `window` is 2–24, default 3 | grain | metric's format |
| `rank` | `RANK() OVER (PARTITION BY period ORDER BY m DESC)` | at least one dimension | number |

A metric is **additive** when its expression combines only `sum` or `count`
measures with `+` and `−` (for example `sla_measured − sla_met`). Shares and running totals of ratios or averages are
rejected with a clear message instead of returning wrong numbers.

Window functions run before `LIMIT`, so a share is a share of the whole
result, not of the visible page.

Time comparisons (`difference`, `percent_change`, `moving_average`) compare
against the previous period *returned*. The time axis is a dense monthly or
weekly series in practice; this caveat is shown in the field's help text.

### 1.4 Ranking filters

`QueryService` resolves each `top` or `bottom` filter before compiling:

1. Run `{metrics: [metric], dimensions: [dimension], …the other filters and
   the time range…, sort: metric desc/asc, limit: n}` under the **same**
   security context.
2. Replace the ranking filter with `{dimension, op: "in", value: members}`.

The SQL in the evidence therefore lists the exact qualifying members. The
stored query keeps the ranking filter, so the result is recomputed on refresh.

### 1.5 Dimension members

`GET /semantic-models/{key}/dimensions/{dimension}/members?search=&limit=`
feeds the members filter. It runs a governed query, so:

- row-level security limits the members a user can pick;
- sensitive dimensions return 403 without `data.sensitive`.

It returns members in alphabetical order, at most 500.

### 1.6 Dashboard filters

`dashboard.filters` holds `DashboardFilter[]`:

```jsonc
{ "dimension": "country", "op": "in", "value": ["India"], "label": "Country", "disabled": false }
```

- When rendering a widget, the API merges the enabled filters into its query,
  **skipping filters on dimensions the widget's model does not have**.
- Editors can save the current filters as the dashboard default
  (`PATCH /dashboards/{id}`).
- Viewers change filters for their session only.
- Filters are ANDed together, as in Sisense's default relation.

---

## 2. Widget Studio (web)

A full-screen dialog opened from **Add widget** or from a widget's **Edit**
action in layout mode.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Title [________________]                                   Cancel  Save      │
├───────────────┬─────────────────────────────────────────┬────────────────────┤
│ CHART         │                                         │ DESIGN             │
│ ▢▢▢▢▢▢▢       │                                         │ Subtype  ○ ○ ○     │
│ ▢▢▢▢▢▢▢       │            live preview                 │ Legend   ▣ bottom  │
│ MODEL [apps▾] │       (the real governed query)         │ Labels   ▢ values  │
│ CATEGORY      │                                         │ Y axis   min max   │
│  [region ✕]   │                                         │          grid  log │
│ VALUES        │                                         │ Markers  ▣         │
│  [Apps ⋯ ✕]   │                                         │ Reference line     │
│  + add value  │                                         │ Number format      │
│ BREAK BY      │                                         │ Data limit [12]    │
│  [channel ✕]  │                                         │                    │
│ FILTERS       │                                         │                    │
│  + filter     │  rows · ms · cached · SQL (if allowed)  │                    │
└───────────────┴─────────────────────────────────────────┴────────────────────┘
```

### 2.1 Chart catalogue and wells

| Chart | Subtypes | Wells |
|---|---|---|
| Column | classic, stacked, 100% stacked | Category (dimension or time) · Values 1–8 · Break by (if 1 value) |
| Bar (horizontal) | classic, stacked, 100% stacked | as column |
| Line | basic, spline, step | X axis (time or dimension) · Values 1–8 · Break by |
| Area | basic, stacked, 100% stacked | as line |
| Pie | pie, donut | Category · Value (exactly 1) |
| Funnel | none | Stage (dimension) · Value |
| Treemap | none | Category · Value |
| Scatter | none | Point (dimension) · X value · Y value |
| Heatmap | none | X (time) · Y (dimension) · Value |
| Map | choropleth | Country · Value |
| Indicator (KPI) | none | Value 1–4 (with a comparison period) |
| Gauge | none | Value · Min / Max / Target in design |
| Table | none | Columns (dimensions) · Values |
| Pivot | none | Rows · Columns (dimension or time) · Values · grand totals |

Switching chart type keeps every field that fits the new wells and reports
the ones it dropped.

### 2.2 Value field menu (⋯)

- **Quick function:** none, % of total, running total, year to date,
  difference vs previous, % change vs previous, moving average (window),
  rank.
- **Sort:** none, ascending or descending (one sort key per query).
- **Colour:** auto, or palette slots 1–8. Colour follows the series.
- **Conditional colour** (single-series bar, column and table): rules
  `{op, value, status: good|warning|critical}`, evaluated in order.

### 2.3 Category and time field menu

- **Time grain:** day, week, month, quarter or year.
- **Sort:** by label or by value.
- **Top N:** creates a ranking filter on this field.

### 2.4 Design panel

| Group | Options |
|---|---|
| Subtype | per §2.1 |
| Legend | show; position (top or bottom) |
| Value labels | show values; show % (pie) |
| Axes | X labels, Y gridlines, Y min / max, logarithmic, Y title |
| Lines | markers, line width (thin, regular or thick) |
| Reference line | value + label (for example, a target) |
| Number format | auto, number, currency or percent; decimals (auto or 0–4); abbreviate (auto, none, K, M, B) |
| Data limit | max categories (1–500) |
| Table / Pivot | rows per page; grand totals (pivot) |
| Gauge | min, max, target |

Options are stored in `widget.viz` (`VizOptions` in `core/models.ts`), with
the API's query held in `widget.query`.

### 2.5 Live preview

The preview runs the real query through `POST /query`, debounced by 350 ms.
It therefore shows the true governed result for the author, under their row-
and column-level security, with row count, duration and the cache flag.
Query errors (for example, a non-additive running total) appear inline next to
the field that caused them.

---

## 3. Dashboard filter bar

```
[ Country: India, Japan ✕ ] [ Institution: top 10 by Applications ✕ ] [ + Filter ]  ·  Reset  ·  Save as default
```

Selecting **+ Filter** walks the user through four steps:

1. Pick a dimension (searchable, grouped by model).
2. Pick a filter type:
   - **Members**: a searchable checklist with include or exclude.
   - **Text**: contains, does not contain, starts with or ends with.
   - **Numeric**: between, ≥, ≤, and so on, for numeric dimensions.
   - **Ranking**: top or bottom N by a metric.
3. Apply.
4. Optionally, pause a chip without removing it (the `disabled` flag).

Each chip has a plain-language label ("Country is not China"), so the state
reads without opening it.

---

## 4. Accessibility and design rules

- The studio and filter popovers are dialogs with a CDK focus trap; Escape
  closes them.
- Wells are lists of buttons and can be operated by keyboard; every control
  has a label.
- Every chart keeps its table view; the pivot *is* a table.
- Colour is never the only carrier of meaning: conditional colours carry
  status labels in tooltips and tables, and series have a legend when there
  is more than one.
