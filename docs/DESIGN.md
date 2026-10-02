# Design system — "Signal"

**Intent:** a serious intelligence product, not a dashboard template. Information is composed by typography and hairlines rather than boxes everywhere; the most important number or sentence on each screen is unmistakable.

* **Environments:** *Dark Intelligence* (default: layered ink surfaces, restrained glow) and *Light Intelligence* (warm paper), each designed separately. Industry/brand themes (Executive, Financial, Government, Healthcare, Technology, Operations, Minimal) change the accent only.
* **Colour roles:** amber **signal** = UI emphasis only; violet = AI-generated content; categorical data palette validated with the CVD/contrast checker on both surfaces (all hard gates pass; light-mode contrast relief provided by every chart's table view); reserved status colours always paired with a glyph and label.
* **Type:** Inter Tight for UI and figures (proportional for hero numbers, tabular in tables), JetBrains Mono for technical provenance.
* **Charts:** ECharts driven by CSS tokens (re-theme live), thin marks, hairline grids, one axis, crosshair tooltips, partial periods dashed, targets as reference lines, anomaly markers, table toggle. Recommended chart chosen by rule (time → line/area, stages → funnel, geography → map with ranked alternative, time × category → heatmap, three measures → 3D scatter); no pies for comparisons.
* **3D:** dotted-land globe with value columns (rotate, zoom, tap to drill), always with a 2D alternative; disabled gracefully without WebGL; auto-rotation off under reduced motion.
* **Motion:** staggered rise on load, smooth chart data transitions, live agent pipeline, generation timelines; no animation that isn't feedback.
* **States:** contextual working states ("Comparing periods → Decomposing → Ranking drivers"), designed empty states with a next action, errors that say what failed, when data last succeeded, Retry, and technical details on request.
* **Mobile:** 4-column reflow with KPIs first, swipeable dashboard sections and report pages, bottom navigation with the AI orb always reachable, bottom sheets, large targets, offline snapshot of the briefing clearly labelled as cached.
