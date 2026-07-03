# ES 5.6 Compatibility Audit — Fix Plan

**Scope:** Binder (MoMA DRMC, AtoM fork, branch `qa/0.9.x`) against Elasticsearch **5.6.16**.
**Date:** 2026-07-04. All paths relative to `/Users/daejinan/claude/moma-binder/binder`.

---

## Vendored Elastica assessment

`vendor/elastica/Elastica/` is an **Elastica 5.x-generation client** (ES 5-era, ~5.2/5.3):

- PSR-style `Elastica\` namespace; `Reindex.php` present (5.x-only class); `Query/Percolate.php`, `Query/MatchNone.php`, `Query/ParentId.php`, full `Aggregation/` directory (Terms, Range, Filter, Filters, DateHistogram, TopHits, …).
- `Query.php` has `setSize()` (:208), `setStoredFields()` (:252), `addAggregation()` (:315), `setSource()` (:411), `setPostFilter(AbstractQuery)` (:425). It has **no** `setFields()`, `setLimit()`, `addFacet()`, or `setFilter()`.
- **No `Elastica\Facet\` namespace and no `Elastica\Filter\` namespace exist anywhere under vendor.** Every `new \Elastica\Facet\*` or `new \Elastica\Filter\*` call site in app code is therefore an immediate **PHP class-not-found fatal** — the request dies before any HTTP call to ES. Same for calls to the removed methods above (undefined-method fatal) and to `ResultSet::hasFacets()/getFacets()` (vendored `ResultSet.php` only has `hasAggregations()`/`getAggregations()`/`getAggregation()`, :112–:140).
- The deprecated `Elastica\Query\Bool` BC shim **is** retained (`Query/Bool.php`, `class Bool extends BoolQuery` + `E_USER_DEPRECATED`); it works on the PHP 5.6 runtime and emits valid `bool` DSL.

Consequence: the legacy AtoM UI failures below are PHP fatals in the client layer, not ES 400s — but the underlying APIs (facets, standalone filter DSL, top-level `fields`, `missing`/`filtered` queries) were all removed server-side in ES 2.0/5.0 anyway, so shimming the classes back is not an option. Code must be ported to aggregations + bool-query filter context.

---

## Priority 0 — `php symfony search:populate`

**No confirmed blocker.** `plugins/arElasticSearchPlugin/` (including the DRMC-specific `arElasticSearchAip`/`Tms`/etc. classes) and `lib/task/` were swept for removed APIs (`Elastica\Facet`, `Elastica\Filter`, `addFacet`, `setLimit`, `setFields`, `hasFacets`/`getFacets`) and came back clean. The single hit — `plugins/arElasticSearchPlugin/lib/arElasticSearchPluginUtil.class.php:182` `$query->setFields(...)` — is on a type-hinted `\Elastica\Query\QueryString` (see :66), and `QueryString::setFields()` exists in the vendored client (`Query/QueryString.php:212`) and maps to `query_string.fields`, which is valid ES 5.6 DSL. **Populate/reindex should run as-is.**

---

## Priority 1 — Binder REST API (`arRestApiPlugin`, DRMC Angular frontend)

Small, independent, one-line-ish fixes. Do these first; they unblock the production frontend.

| # | Location | Problem | Fix |
|---|----------|---------|-----|
| 1.1 | `plugins/arRestApiPlugin/modules/api/actions/actorsBrowseAction.class.php:49` | `$query->setFields(array('slug','createdAt','updatedAt','sourceCulture','i18n'))` — `Query::setFields()` no longer exists (ES 5.0 renamed top-level `fields` → `stored_fields`); PHP fatal → HTTP 500 on every `GET /api/actors`. | Replace with `$query->setSource(array(...))` (same list) — matches sibling actions (`informationobjectsWorksBrowseAction.class.php:204`) and the downstream `$hit->getData()` reads. Do **not** use `setStoredFields`: none of these fields are `store: true`, so it would silently return empty hits. |
| 1.2 | `plugins/arRestApiPlugin/modules/api/actions/informationobjectsBrowseAction.class.php:85` | `$results[$hit->getId()] = $hit->getFields()` — query uses `setSource()` (:61), so hits have `_source` and never a `fields` key; `Result::getFields()` returns `[]` → **silent data loss** (rows in `GET /api/informationobjects` lose slug, identifier, levelOfDescriptionId, parentId, createdAt, …). | Use the `$doc = $hit->getData()` already fetched at :84: `$results[$hit->getId()] = $doc;` then keep the existing `id`/`title` assignments. |
| 1.3 | `plugins/arRestApiPlugin/modules/api/actions/aipsFilesAction.class.php:32` and `informationobjectsFilesAction.class.php:33` | Sort map `'size' => 'byteSize'` targets an unmapped root field — ES 5.6 throws `search_phase_execution_exception` ("No mapping found for [byteSize]") when `sort=size` is requested. | Change to `'size' => 'digitalObject.byteSize'` (explicitly mapped integer in mapping.yml; no reindex needed). |

---

## Priority 2 — Legacy AtoM browse/search UI (one coordinated migration)

Every legacy browse/search/autocomplete page (`/search`, `/informationobject/browse`, actor/repository/term/digitalobject browse, advanced search, autocompletes) 500s today. These routes are live (generic `/:module/:action` routes in `apps/qubit/config/routing.yml`), even though the DRMC Angular UI doesn't link them.

**This is one cluster, not N independent bugs.** `DefaultBrowseAction`, its seven subclasses, `QubitAclSearch::filterDrafts()`, and `QubitSearchPager` share the removed Facet/Filter APIs; a partial fix just moves the fatal to the next line. Port them together, mirroring upstream AtoM 2.4/2.5 (which already did this exact conversion — lowest-risk path is to port upstream's versions of these files).

**Cheap alternative:** if the legacy AtoM UI is not a supported surface for Binder, `forward404()` these actions / exclude the routes and skip 2.A–2.I entirely.

### 2.A `apps/qubit/modules/default/actions/browseAction.class.php` — core of the cluster

| Line(s) | Problem | Fix |
|---------|---------|-----|
| :28, :47, :53 | `new \Elastica\Facet\Terms` / `Facet\Query` — classes absent → fatal; facets removed in ES 2.0. | `\Elastica\Aggregation\Terms` (setField/setSize); `'query'` type → `\Elastica\Aggregation\Filter` wrapping an `\Elastica\Query\Term`. |
| :32, :91 | `$this->query->addFacet()` — undefined method. | `$this->query->addAggregation()` (Query.php:315). |
| :40 | `new \Elastica\Facet\Range` — same fatal, but branch is dead (no subclass configures `'type' => 'range'`). | Removed/converted naturally by the same port (`\Elastica\Aggregation\Range`). |
| :65, :81, :103, :192, :270 | `new \Elastica\Filter\BoolFilter` / `Filter\Term` / `Filter\Bool` — Filter namespace gone; :270 is unconditional so **every** subclass request fatals here. | `\Elastica\Query\BoolQuery` + `\Elastica\Query\Term` in filter context (`addFilter()`/`addMustNot()`); per-facet filters (hideDrafts, languages) become the terms agg nested inside an `\Elastica\Aggregation\Filter`. |
| :88 | `$facet->setFilter()` on facet object. | Wrap the aggregation in `\Elastica\Aggregation\Filter`. |
| :197 | `$this->query->setFilter()` — removed method. | `$this->query->setPostFilter($bool)` (keeps facet counts ignoring selection) or fold into main bool via `addFilter()`. |
| :262 | `$this->query->setLimit()` — removed; this is the **first fatal actually reached** at runtime. | `setSize()`. Smoke test: `GET /search?query=x` returns 200. |
| :135, :145 (populateFacets, :132–:218) | `$resultSet->hasFacets()/getFacets()` undefined; parser expects pre-2.0 shape (`terms[].term/count`). | Use `hasAggregations()/getAggregations()`; adapt to `buckets[].key/doc_count` (filter-wrapped aggs: descend into sub-agg; `'query'`-type count = filter agg's `doc_count`). Keep the output array shape templates expect, or update the browse templates. Also replace the languages-recount hack (:179–:206, raw `unset('filter')` + `new \Elastica\Filter\Bool`). |

### 2.B `plugins/qbAclPlugin/lib/QubitAclSearch.class.php` — ACL/draft filtering

| Line(s) | Problem | Fix |
|---------|---------|-----|
| :59, :66, :175, :192, :195 | `new \Elastica\Filter\Term` — class absent. | `\Elastica\Query\Term`. |
| :68, :152, :200 | `Filter\Not` / `Filter\BoolNot` (and/or/not filters removed in ES 5.0). | `\Elastica\Query\BoolQuery` with `addMustNot()`. |
| :130, :150 | `Filter\Ids`. | `\Elastica\Query\Ids`. |
| :166 | `filterDrafts(\Elastica\Filter\BoolFilter $filterBool)` — stale type hint. | Change signature to `\Elastica\Query\BoolQuery`; `setPostFilter(AbstractQuery)` already accepts it. **Update every caller in the same commit** (2.A, 2.D, 2.E, 2.F, plus informationobject/browseAction:222, search/indexAction:186, search/advancedAction:485) — fixing this file alone does not stop the fatals, since callers construct the missing Filter classes first. |

### 2.C–2.I Remaining call sites

| # | Location | Problem | Fix |
|---|----------|---------|-----|
| 2.C | `apps/qubit/modules/search/actions/advancedAction.class.php:203–205` | `new \Elastica\Filter\Missing` + `new \Elastica\Query\Filtered` — both classes absent; `missing`/`filtered` removed in ES 5.0 (copyright "Unknown" filter). | Replace :201–:213 with `$noStatus = new \Elastica\Query\BoolQuery; $noStatus->addMustNot(new \Elastica\Query\Exists('copyrightStatusId'));` then `addShould($termQuery); addShould($noStatus);` on an inner bool added via `addMust()` — **must stay a should-clause to preserve OR semantics**; drop the now-unused MatchAll. |
| 2.D | `apps/qubit/modules/informationobject/actions/autocompleteAction.class.php:40, :44, :78` | `setLimit()` undefined (:40); `Filter\BoolFilter` (:44) and `Filter\Query` (:78) absent; default sort (:41) targets text field `i18n.<culture>.title` → 5.6 "Fielddata is disabled". | `setSize()`; `BoolQuery` + direct `Query\Term` via `addMust()` applied with `setPostFilter()`; emptiness guard `count($bool->toArray())` → `count($bool->getParams())` (BoolQuery::toArray() is never empty); sort on `i18n.<culture>.title.untouched` (only `untouched` exists — no `.alphasort` subfield in this mapping). |
| 2.E | `apps/qubit/modules/search/actions/autocompleteAction.class.php:83, :121` | `Query::setFields()` undefined (:83); `Filter\BoolFilter` absent (:121). (REST twin `searchAutocompleteAction.class.php:81` was already fixed; this one was missed.) | :83 → `setSource($item['fields'])`; :121 → `BoolQuery` + inline drafts term filter or the migrated 2.B `filterDrafts()`. |
| 2.F | `apps/qubit/modules/repository/actions/contextMenuComponent.class.php:42, :46, :49` | `setLimit()` undefined; sort option `ignore_unmapped` rejected by 5.x strict sort parsing; `Filter\BoolFilter` absent. | `setSize()`; `'unmapped_type' => 'keyword'`; `BoolQuery` (keep `setPostFilter`, fix guard to `getParams()`); depends on 2.B. |
| 2.G | `apps/qubit/modules/default/actions/moveAction.class.php:101` | `$this->query->setLimit($request->limit)` — undefined method; move-description dialog fatals. | `setSize()` (the `setFields` at :114 is on a QueryString — fine). |
| 2.H | Subclass activation sites: `informationobject/actions/browseAction.class.php:32`, `repository/actions/browseAction.class.php:32`, `term/actions/browseTermAction.class.php:26`, `digitalobject/actions/browseAction.class.php:31`, `search/actions/indexAction.class.php:29`, `actor/actions/browseAction.class.php:35` (+ `search/advancedAction`) | Their `$FACETS` arrays trigger the 2.A path; their `populateFacet()` overrides read the pre-2.0 `terms/term/count` shape. | Port each override to the aggregation bucket shape (`buckets[].key/doc_count`) in the same pass as 2.A. |
| 2.I | `lib/search/QubitSearchPager.class.php:89, :94, :106` | `getFacets()/getFacet()/hasFacets()` delegate to removed ResultSet methods — loaded gun once 2.A lands (currently unreachable). | Delete them + the `__get('facets')` case (populateFacets assigns `$pager->facets` as a plain property at browseAction:217), or reimplement over `getAggregations()`. Also guard `apps/qubit/modules/search/templates/_facetLanguage.php:23` (unguarded read of the `unique` pseudo-term). |

---

## Priority 3 — Hygiene (not ES-5.6 blockers; fix opportunistically)

| Location | Note |
|----------|------|
| `plugins/arRestApiPlugin/modules/api/actions/informationobjectsBrowseAction.class.php:31` | `new \Elastica\Query\Bool` — deprecated BC shim; works on the PHP 5.6 runtime, emits valid `bool` DSL, but fatals on any future PHP 7+ migration. One-line change to `BoolQuery` to match every sibling action. |
| `plugins/arRestApiPlugin/lib/QubitApiAction.class.php:242–243` | `buildEsAgg()` constructs `Histogram`/`DateHistogram` with 1 arg (constructor wants 3). Non-fatal warning on PHP 5.6, overwritten by `setField()`/`setInterval()`; becomes `ArgumentCountError` on PHP 7.1+. |
| PHP-7 portability generally | e.g. `summaryArtworkByDateAction.class.php:118` uses `split()` (removed PHP 7.0). Out of ES scope; flag for any runtime upgrade. |

---

## Suggested execution order

1. **P1 (REST API)** — three surgical edits; verify with `GET /api/actors`, `GET /api/informationobjects` (field completeness), `GET /api/aips/<uuid>/files?sort=size`.
2. **Decide legacy-UI strategy** — port (2.A→2.B→2.C–2.G in one branch, then 2.H/2.I) vs. 404 the routes.
3. If porting: smoke tests = `GET /search?query=x`, `/informationobject/browse` with a facet selected as draft-restricted user, `/search/advanced` with copyright "Unknown", each autocomplete endpoint.
4. **P0 regression check** — run `php symfony search:populate` end-to-end once against ES 5.6.16 to confirm the clean sweep empirically.
