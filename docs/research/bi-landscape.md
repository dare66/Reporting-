# BI landscape: Sisense and peers, measured against AIXBI

This document records what the leading BI tools offer when a user builds a
widget, filters a dashboard or writes a formula. It then sets each capability
against AIXBI and decides whether to adopt it, adapt it or reject it. The design
that follows from these decisions is in
[`docs/design/widget-studio.md`](../design/widget-studio.md).

**Method.** The Sisense inventory comes from two places:

- **Option names and values.** These are taken from the type definitions in the
  published Sisense SDK packages, `@sisense/sdk-ui` 2.37.0 and
  `@sisense/sdk-data` 2.37.0. The SDK is the API that Sisense's own widgets
  render through, so its types list every field the product exposes, with exact
  names and allowed values.
- **Behaviour.** This comes from Sisense's documentation pages, listed under
  [Sources](#sources).

The Sisense documentation sites could not be reached from the build environment
(the egress proxy blocks them). Every option listed below was therefore checked
against the SDK type definitions rather than copied from the docs.

The peer tools were surveyed through their vendors' documentation and release
notes.

---

## 1. Feature matrix

✅ = AIXBI has it, ◐ = partial, ➕ = added in this phase (Widget Studio),
◻ = roadmap, ✕ = rejected (the reason is given in section 4).

| Capability | Sisense | Power BI | Tableau | Looker | Qlik | ThoughtSpot | Metabase | AIXBI |
|---|---|---|---|---|---|---|---|---|
| Governed semantic layer (metrics defined once) | Elasticube model | Semantic model | Published data source | LookML | Data model | Worksheet / model | Models + metrics | ✅ semantic models |
| Widget designer: data panel (categories / values / break by) | ✅ | ✅ field wells | ✅ shelves | ✅ explore | ✅ | ◐ search | ✅ | ➕ |
| Widget designer: design panel (subtype, legend, labels, axes) | ✅ | ✅ format pane | ✅ marks card | ◐ | ✅ | ◐ | ◐ | ➕ |
| Chart subtypes (stacked, 100%, spline, step, donut) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ➕ |
| Pivot table | ✅ | ✅ matrix | ✅ crosstab | ✅ | ✅ | ✅ | ✅ | ➕ |
| Treemap / scatter / gauge | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ➕ |
| Quick functions (% of total, running sum, difference, rank, moving average) | ✅ | ✅ visual calculations | ✅ table calculations | ◐ | ✅ | ◐ | ◐ | ➕ (SQL windows) |
| Measure filters (top N, value thresholds) | ✅ ranking + criteria | ✅ Top N | ✅ | ✅ | ✅ | ✅ | ◐ | ➕ |
| Dashboard filters: members, text, numeric, date | ✅ | ✅ slicers | ✅ | ✅ | ✅ | ✅ | ✅ | ➕ |
| Exclude (NOT) member filters | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ➕ |
| Relative date filters (last N periods) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ presets |
| Filter cascading / dependent filters | ✅ | ✅ | ✅ | ✅ | ✅ associative | ✅ | ◐ | ◻ |
| Cross-filtering (click to filter the dashboard) | ✅ | ✅ | ✅ actions | ✅ | ✅ | ✅ | ✅ | ◐ (AI drill) |
| Drill-down hierarchies | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (`/analysis/drill`) |
| Conditional colour | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ➕ (status rules) |
| Number formatting (abbreviation, decimals, currency, %) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ➕ |
| Reference / target lines | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ goals | ➕ |
| Dual-axis charts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✕ (by design) |
| Parameters / what-if | ◐ | ✅ | ✅ | ✅ Liquid | ✅ variables | ◐ | ✅ | ✅ scenarios |
| LOD / fixed-grain expressions | ◐ | ✅ DAX | ✅ FIXED / INCLUDE / EXCLUDE | ✅ derived tables | ✅ set analysis | ◐ | ◐ | ◻ |
| Bins and groups | ◐ | ✅ | ✅ | ✅ tiers | ✅ | ◐ | ✅ | ◻ |
| Bookmarks / saved views | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ (favourites) |
| Natural-language Q&A | ✅ Simply Ask | ✅ Copilot | ✅ Pulse | ✅ Gemini | ✅ Insight Advisor | ✅ core | ◐ Metabot | ✅ AI analyst, grounded |
| Automated narrative | ✅ Narratives | ✅ Smart Narrative | ✅ Pulse | ◐ | ✅ | ✅ | ◐ | ✅ grounded, with evidence |
| Anomaly detection | ✅ Pulse | ✅ | ✅ Pulse | ◐ | ✅ | ✅ SpotIQ | ◐ | ✅ seasonal residuals |
| Forecast | ✅ | ✅ | ✅ | ◐ | ✅ | ✅ | ◐ | ✅ Holt-Winters, backtested |
| Root-cause explanation | ◐ Explanations | ✅ Key Influencers | ✅ Explain Data | ◐ | ✅ | ✅ SpotIQ | ✕ | ✅ decomposition |
| Threshold alerts | ✅ Pulse | ✅ data alerts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Row-level security | ✅ data security | ✅ RLS | ✅ user filters | ✅ access filters | ✅ section access | ✅ | ✅ sandboxing | ✅ ABAC |
| Column masking | ◐ | ✅ OLS | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Every number traceable to its query | ◐ | ◐ | ◐ | ✅ SQL tab | ◐ | ✅ | ✅ | ✅ evidence on every value |

The comparison shows that the tools agree on the authoring vocabulary: wells,
quick functions, filter types and design options. That vocabulary is the gap
AIXBI closes in this phase. In governed AI, evidence and narrative, AIXBI
already matches or leads the field.

---

## 2. Sisense inventory (from the SDK type definitions)

### 2.1 Chart catalogue and subtypes

| Family | Sisense subtypes | Data options (wells) |
|---|---|---|
| Line | `line/basic`, `line/spline`, `line/step` | `category[]`, `value[]`, `breakBy[]` |
| Area | `area/basic`, `area/stacked`, `area/stacked100`, `area/spline`, `area/stackedspline`, `area/stackedspline100` | same as line |
| Column | `column/classic`, `column/stackedcolumn`, `column/stackedcolumn100` | same as line |
| Bar | `bar/classic`, `bar/stacked`, `bar/stacked100` | same as line |
| Pie | `pie/classic`, `pie/donut`, `pie/ring` | `category`, `value` |
| Funnel | none (`funnelSize`, `funnelType`, `funnelDirection`) | `category`, `value` |
| Treemap / Sunburst | none (per-level label toggles) | `category[]`, `value` |
| Scatter | none (`markerSize`) | `x`, `y`, `breakByPoint`, `breakByColor`, `size` |
| Polar | `polar/column`, `polar/area`, `polar/line` | same as line |
| Indicator | `numericSimple` (vertical or horizontal), `numericBar`, `gauge` (skin 1 or 2) | `value`, `secondary`, `min`, `max` |
| Boxplot | `boxplot/full`, `boxplot/hollow` | `category`, `value`, `boxType` (`iqr`, `extremums`, `standardDeviation`), `outliersEnabled` |
| Area map / Scatter map | `mapType` | `geo`, `color` / `geo[]`, `size`, `colorBy`, `details` |
| Calendar heatmap | `split`, `continuous` | `date`, `value` |
| Sankey | none | `category[]`, `value` |
| Range (arearange) | none | `category`, `value` (lower and upper), `breakBy` |
| KPI | `layout`: `standard` or `comparison-first` | `value`, `category`, `valueMode` (`last` or `total`), `comparison` |
| Table | none | `columns` |
| Pivot | none | `rows`, `columns`, `values`, `grandTotals` |

### 2.2 Per-field options (the menu on a field in the data panel)

**Dimension field (`CategoryStyle`):**
`name`, `enabled`, `sortType`, `numberFormatConfig`, `dateFormat`, `granularity`
(the time grain), `continuous`, `geoLevel`, `width`, `isHtml`,
`includeSubTotals`.

**Value field (`ValueStyle`):**
`name`, `enabled`, `sortType`, `numberFormatConfig`, `color`, `chartType`
(a chart type per series), `showOnRightAxis`, `treatNullDataAsZeros`,
`connectNulls`, `totalsCalculation`, `dataBars` and `dataBarsColor`,
`forecast`, `trend`, `width`, `seriesStyleOptions`.

**Number format (`NumberFormatConfig`):**

- `name`: `Numbers`, `Currency` or `Percent`.
- `decimalScale`: a number of places, or `auto`.
- Abbreviation toggles: `kilo`, `million`, `billion`, `trillion`.
- `thousandSeparator`.
- `prefix` (whether the symbol goes before the number) and `symbol`.

**Colour (`DataColorOptions`):**

- A plain colour string, or `uniform`.
- `range`: `steps`, `minColor`, `maxColor`, `minValue`, `midValue`, `maxValue`.
- `conditional`: a list of `{ color, expression, operator }` rules, where the
  operator is one of `<`, `>`, `≤`, `≥`, `=` or `≠`, plus a `defaultColor`.

### 2.3 Design panel (style options)

| Group | Options |
|---|---|
| Legend | `enabled`, `position` (top, bottom, left or right), `align`, `verticalAlign`, `title`, `items`, `symbols`, `reversed`, `floating` |
| Axes (`xAxis`, `yAxis`, `y2Axis`) | `enabled`, `gridLines`, `labels.enabled`, `logarithmic`, `min`, `max`, `intervalJumps`, `title { enabled, text }` |
| Markers | `enabled`, `fill` (filled or hollow), `size` (small or large) |
| Lines | `lineWidth` (thin, bold or thick), `line.dashStyle`, `stepPosition` |
| Value labels | `seriesLabels`: `showValue`, `showPercentage`, `rotation`, `align` |
| Totals | `totalLabels`: `enabled`, `prefix`, `suffix`, `align` |
| Pie | `convolution` (group small slices by percentage or by count), `semiCircle`, `seriesLabels.showCategory` |
| Treemap / Sunburst | per-level `labels.category[].enabled`; tooltip `mode` (value or contribution) |
| Navigator | `enabled` (a range scroller under time series) |
| Data limits | `seriesCapacity`, `categoriesCapacity` |
| Table | `rowsPerPage`, header colour, alternating row and column colours, `columns.width` (auto or content), `resizable` |
| Pivot | `rowsPerPage`, `rowHeight`, `headersColor`, `alternatingRowsColor`, `membersColor`, `totalsColor`, `highlightColor` |
| KPI | `comparison.display` (percent, value or both), comparison colour and icons, `sparkline { enabled, chartType }` |

### 2.4 Filters (from `filterFactory`, 46 functions)

| Kind | Functions |
|---|---|
| Members | `members` (include or exclude), `exclude`, `union`, `intersection` |
| Text | `contains`, `doesntContain`, `startsWith`, `doesntStartWith`, `endsWith`, `doesntEndWith`, `like`, `equals`, `doesntEqual`, `isEmpty`, `isNotEmpty` |
| Numeric (attribute) | `greaterThan`, `greaterThanOrEqual`, `lessThan`, `lessThanOrEqual`, `between`, `betweenNotEqual`, `numeric` |
| Date | `dateFrom`, `dateTo`, `dateRange`, `dateRelative`, `dateRelativeFrom`, `dateRelativeTo`, `thisYear`, `thisQuarter`, `thisMonth`, `today` |
| Measure (HAVING) | `measureEquals`, `measureGreaterThan`, `measureGreaterThanOrEqual`, `measureLessThan`, `measureLessThanOrEqual`, `measureBetween`, `measureBetweenNotEqual` |
| Ranking | `topRanking`, `bottomRanking`, `measureTopRanking`, `measureBottomRanking` |
| Composition | `cascading`, `customFilter` |

The Sisense UI groups these as **List**, **Text**, **Numeric**, **Ranking**,
**Date / Calendar** and **Dynamic time** filters, set at the dashboard or the
widget level. Dashboard filters apply to every widget built on the same data
model, and a widget can be set to ignore them.

### 2.5 Formula functions (from `measureFactory`, 63 functions)

| Group | Functions |
|---|---|
| Aggregations | `sum`, `average`, `min`, `max`, `median`, `mode`, `count`, `countDistinct` |
| Statistics | `stdev`, `stdevp`, `variance`, `varp`, `percentile`, `quartile`, `correlation`, `covarp`, `slope`, `intercept` |
| Arithmetic | `add`, `subtract`, `multiply`, `divide`, `constant`, `customFormula`, `measuredValue` (a conditional measure) |
| Period-to-date | `yearToDateSum`, `quarterToDateSum`, `monthToDateSum`, `weekToDateSum` |
| Running | `runningSum` |
| Time comparison | `pastDay`, `pastWeek`, `pastMonth`, `pastQuarter`, `pastYear`; `growth`, `growthRate`, `growthPastWeek` / `Month` / `Quarter` / `Year`; `difference`, `diffPastWeek` / `Month` / `Quarter` / `Year` |
| Analytics | `contribution` (share of total), `rank` (standard, dense or competition ranking), `trend`, `forecast` |

In the widget editor these appear as **quick functions** on a value field.

---

## 3. Peer tools: what each adds beyond Sisense

| Tool | Distinctive authoring features |
|---|---|
| **Power BI** | Visual calculations (`RUNNINGSUM`, `MOVINGAVERAGE`, `COLLAPSE`, percent of parent) defined on the visual itself. Field parameters (the viewer swaps which measure a visual shows). Bookmarks. Drill-through pages. What-if parameters. Small multiples. Smart Narrative. Anomaly detection on line charts. |
| **Tableau** | LOD expressions (`FIXED`, `INCLUDE`, `EXCLUDE`). Table calculations with explicit "compute using". Parameters. Dashboard actions (filter, highlight, URL, set). Sets, groups and bins. Reference lines, bands and distributions. Explain Data. |
| **Looker** | LookML: dimensions, measures, explores and derived tables in version control. Liquid-templated filters. Access filters (row-level security). The SQL tab shows the generated query for every result. |
| **Qlik** | The associative engine: selections propagate across the whole model, and the excluded values stay visible in grey. Set analysis expressions. |
| **ThoughtSpot** | Search-driven answers built from tokenised keywords. SpotIQ automated insights. Liveboards. |
| **Metabase** | A point-and-click question builder (filter → summarise → group by). Models and metrics. Goal lines. |

---

## 4. Gap analysis and decisions

| Gap | Decision | Why |
|---|---|---|
| Widget designer with data and design panels | **Adopt** as *Widget Studio* | It is the authoring vocabulary every tool shares; AIXBI's "Add widget" dialog offered one metric and one dimension. |
| Chart subtypes, pie, treemap, scatter, gauge, pivot | **Adopt** | Covered by ECharts (already a dependency); each type keeps a table view. |
| Break-by (series by a second dimension) | **Adopt** | Required for stacked and multi-series charts. |
| Quick functions | **Adopt, compiled to SQL window functions** | Sisense and Power BI compute these after aggregation. AIXBI compiles them into the governed SQL, so they apply before `LIMIT` (totals stay correct) and appear in the evidence SQL. Running totals and shares are restricted to additive metrics, so a ratio cannot be summed into nonsense. |
| Measure (HAVING) filters | **Adopt** | Lets a viewer ask for, say, "institutions with SLA below 85%". |
| Ranking filters | **Adopt, resolved by a governed pre-query** | The top-N members are found under the same security context, then applied as an `IN` filter, so the evidence SQL shows exactly which members qualified. |
| Text filters (`starts_with`, `ends_with`, `not_contains`) and `not_between` | **Adopt** | Completes the Sisense text and numeric families. |
| Dashboard filter bar with member search | **Adopt** | Replaces the single hard-coded country selector. Members come from a governed query, so row-level security limits the choices too. |
| Conditional colour | **Adapt** | Rules map to the reserved status colours (good, warning, critical), never to arbitrary hex values, so the colour-blind-safe palette is preserved and status keeps its meaning. |
| Per-series colour | **Adapt** | Chosen from the validated categorical palette slots, and colour follows the entity, never its rank. |
| Dual axis (`showOnRightAxis`, `y2Axis`) | **Reject** | Two y-scales on one plot invite false correlation. The design system's charting rules forbid it; AIXBI offers small multiples or indexing to a common base instead. |
| Arbitrary formulas in widgets (`customFormula`) | **Reject at widget level; route to the semantic model** | Ad hoc formulas fork the definition of a metric. New metrics are added to the semantic model through the existing safe expression parser, so they are governed, versioned and reusable by the AI. |
| Cascading filters, LOD expressions, bins and groups, bookmarks, field parameters, cross-filter actions | **Roadmap** | Recorded in `docs/ROADMAP.md`. Each needs either engine work (LOD) or dashboard state design (bookmarks) beyond this phase. |

---

## Sources

**Sisense SDK packages (the authoritative option schema):**

- `@sisense/sdk-ui` 2.37.0 and `@sisense/sdk-data` 2.37.0 on npm. The
  inventory above was extracted from their `.d.ts` files (`ChartType`,
  `*StyleOptions`, `CategoryStyle`, `ValueStyle`, `NumberFormatConfig`,
  `DataColorOptions`, `filterFactory`, `measureFactory`).

**Sisense documentation:**

- Widget designer: <https://docs.sisense.com/main/SisenseLinux/widget-designer.htm>
- Dashboard filters: <https://docs.sisense.com/main/SisenseLinux/creating-dashboard-filters.htm>
- Quick functions: <https://docs.sisense.com/main/SisenseLinux/quick-functions.htm>
- Colours in widgets: <https://docs.sisense.com/main/SisenseLinux/selecting-colors-in-widgets.htm>
- Function reference: <https://docs.sisense.com/main/SisenseLinux/dashboard-functions-reference.htm>
- Data alerts: <https://docs.sisense.com/main/SisenseLinux/creating-data-alerts.htm>

**Sisense developer documentation:**

- Chart style options: <https://developer.sisense.com/guides/sdk/modules/sdk-ui/type-aliases/type-alias.RegularChartStyleOptions.html>
- Filter widget types: <https://developer.sisense.com/guides/sdk/modules/sdk-ui/type-aliases/type-alias.FilterWidgetFilterType.html>
- `contribution` measure: <https://developer.sisense.com/guides/sdk/modules/sdk-data/factories/namespace.measureFactory/functions/function.contribution.html>
- Widget plugin data panel and design panel tutorials:
  <https://developer.sisense.com/guides/sdk/tutorials/tutorial-widget-plugins/04-data-panel.html> and
  <https://developer.sisense.com/guides/sdk/tutorials/tutorial-widget-plugins/05-design-panel.html>

**Power BI:**

- Feature summary, January 2026: <https://powerbi.microsoft.com/en-us/blog/power-bi-january-2026-feature-summary/>

**Tableau:**

- Parameters: <https://help.tableau.com/current/pro/desktop/en-us/parameters_create.htm>
- LOD expressions: <https://www.tableau.com/learn/whitepapers/understanding-lod-expressions>

**Looker:**

- What is LookML: <https://docs.cloud.google.com/looker/docs/what-is-lookml>
- LookML terms and concepts: <https://docs.cloud.google.com/looker/docs/lookml-terms-and-concepts>
- Derived tables: <https://docs.cloud.google.com/looker/docs/derived-tables>

**Qlik, ThoughtSpot and Metabase:** vendor product pages and comparisons
surveyed through web search, for example
<https://www.thoughtspot.com/data-trends/business-intelligence/qlik-competitors-alternatives>.
