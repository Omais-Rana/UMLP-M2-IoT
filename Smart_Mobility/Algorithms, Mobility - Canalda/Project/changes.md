# Change Log

This file records the mobility-platform improvements completed in this conversation and the planned improvements that will be implemented from this point onward.

## Completed

### GTFS normalization and import

- Normalized GTFS booleans, dates, text encoding, and extended times such as `25:30:00`.
- Made calendar weekday parsing depend on column names rather than column order.
- Added feed-qualified calendar uniqueness using `(feed_id, service_id)`.
- Preserved pickup and drop-off types from stop times.
- Added support for heterogeneous optional GTFS files.
- Added feed metadata, agencies, shapes, and source GTFS transfers.
- Added GTFS transfer provenance and feed-qualified transfer endpoints.
- Preserved imported GTFS transfers when generated walking transfers are rebuilt.
- Added imported feed counts and validity metadata to API/bootstrap responses.
- Added mojibake/text normalization for imported names and labels.
- Filtered SNCF imports to routes and trips serving Belfort, Montbéliard, and the relevant regional stops.
- Added a heterogeneous GTFS fixture and normalization tests.
- Added coordinate-aware, accent-insensitive stop search with optional radius filtering.

### GTFS import behavior

- Added support for operator directories and ZIP feeds.
- Added candidate-point and car-park import from local JSON data.
- Added feed validity reporting based on both `calendar.txt` and `calendar_dates.txt`.
- Fixed the advertised `--force` behavior:
  - `--force` clears existing timetable/feed data.
  - Without `--force`, existing records are preserved for incremental updates.
- Made `calendar_dates` imports idempotent with database uniqueness on `(feed_id, service_id, date)` and matching upserts.
- Added synthetic `calendar_dates.txt` coverage for duplicate-safe incremental imports and `--force` rebuilds.
- Switched API and smoke tests to the small heterogeneous GTFS fixture, avoiding unnecessary full-feed imports and reducing test memory use.
- Added an Overpass-based `mobility:import-car-parks` command using the existing `car_parks` schema.
- Added parking importer tests for node/way mapping, private-parking filtering, and provider failures.
- Removed GBFS TLS verification bypasses from station discovery and station imports.
- GBFS and parking failures are now surfaced explicitly instead of silently falling back to mock parking data.
- Added cached live GBFS status retrieval with configurable TTL and explicit request timeouts.
- Preserved existing station availability when live status feeds fail.
- Added focused shared-car tests for same-city exclusion, cross-boundary inclusion, unavailable cars, destination parking, and one-way routing.

### Carpool and planning contract

- Removed `car_mode` from the planning request.
- Removed passenger-seat and `free_seats` calculations.
- Added `share_ride` for passengers without cars.
- Rejected ride sharing for participants who have a personal car.
- Implemented unlimited driver capacity.
- Added explicit passenger-origin pickup behavior for opted-in ride-sharing passengers.
- Kept non-sharing passengers on independent itineraries.
- Removed private-car park/pickup mode selection from the frontend and backend.
- Updated demo data, API tests, unit tests, and planning documentation for the new contract.
- Removed the unreachable legacy branch-and-bound, seat-limit, permutation, and pickup-spot solver methods.
- Added direct carpool coverage for pickup ordering, unlimited capacity, non-sharing passengers, and exactly-once itinerary output.
- Migrated carpool tests away from the full bundled-feed import.

### Feed-qualified routing

- Updated connection scanning to use feed-qualified stop, trip, and route keys.
- Updated transfer lookup and propagation to preserve endpoint feed identity.
- Updated itinerary and meeting-point routing lookups to use feed-qualified records.
- Migrated connection-scan tests to a small database fixture and added feed-qualified assertions.

### GBFS and parking prototype

- Added a prototype one-way GBFS shared-car strategy.
- Restricted GBFS shared-car options to cross-boundary Belfort/Montbéliard journeys.
- Added walking access from the participant origin to a live available car-share station.
- Added a shared-car leg to a destination car park followed by walking to the final destination.
- Kept the GBFS journey one-way without a return-to-station calculation.

### Validation

- Added and maintained focused normalization, stop-search, request, and carpool tests.
- Ran PHP syntax checks and Laravel Pint after relevant PHP changes.
- Built the frontend successfully with Vite.
- Migrated demo, routing, and carpool tests away from full bundled-feed setup where synthetic fixtures are sufficient.
- Full application test suite passes: 27 tests and 121 assertions.
- Real bundled GTFS import passes with 2,387 stops, 89 routes, 10,579 trips, and 264,604 stop times.
- Final frontend production build passes.
- Fixed connection-scan handling for legacy unqualified transfers so stale pre-feed-qualified rows are ignored instead of crashing real-data planning.
- Re-ran the full application test suite after the routing fix: 27 tests and 121 assertions passed.
- Phase 1 memory optimization: added a request-scoped planning dataset so stops, candidates, and routes are loaded once and reused across all participants and strategy evaluation.
- Phase 1 projections now select only the planning columns required by those datasets.
- Phase 2 memory optimization: changed date-specific CSA connection construction to use scalar query results and stream stop-times in order, removing the simultaneous full stop-time collection and grouped copy.
- Phase 2 uses a versioned CSA cache key so old connection-cache payloads are not reused after the representation change.
- Benchmarked real-data itinerary generation with a 512 MB PHP limit:
  - 2 participants: 4.7 s cold / 7.4 s second run, 3 options.
  - 5 participants: 7.3 s cold / 11.7 s second run, 3 options.
  - 10 participants: 10.6 s cold / 14.0 s second run, 3 options.
- Re-benchmarked after Phases 1 and 2 under the default 128 MB PHP limit:
  - 2 participants: completed in 4.8 s, peak approximately 112 MB, 3 options.
  - 10 participants: completed in 10.4 s, peak approximately 118 MB, 3 options.
- The previously observed real-data memory failure no longer reproduces for the tested 2- and 10-participant scenarios.
- Updated the map so every participant's route uses that participant's assigned color, including all transport legs, while dash patterns continue to distinguish walking and passenger legs.
- Added a persistent, labeled meeting-point marker with coordinates and a popup on the map.
- Added meeting-point coordinates to the active option summary so the midpoint is explicit in both the map and results panel.
- Adopted the reference route-search walking policy:
  - reduced origin, candidate, destination, and direct-walk thresholds to 1.5 km;
  - added a walking penalty to route selection so nearby-stop walking is not preferred solely because it arrives slightly earlier;
  - included the complete reconstructed walking distance in candidate scoring.
- Corrected strategy semantics so independent journeys and one-way GBFS journeys no longer report the final destination or parking location as a shared midpoint.
- Updated midpoint ordering so strategies with a genuine shared meeting point are preferred ahead of independent fallback journeys when both are available.
- Added collapsible route-detail cards for itineraries containing transit or other multi-stop transport legs, reducing result-panel height while retaining all leg details.
- Updated the demo contract test to reflect that an independent fallback has no shared meeting point.
- Fixed `arrive_by` planning so the requested time is treated as a hard destination-arrival cutoff instead of being ignored.
- Added an arrival-by search window and regression coverage preventing late options from being returned.
- Corrected shared-hub eligibility so a transit itinerary may use two walking legs of up to 500 m each (origin access plus hub access), instead of incorrectly rejecting it when their combined distance exceeded 500 m. Individual walking legs remain capped at 500 m.
- Moved the planning loader out of the left controls sidebar into a centered full-page modal overlay with a blurred background, wider participant progress layout, and automatic removal when results are displayed.
- Aligned shared meeting-point group-leg walking checks with the current 500 m policy, replacing the remaining 300 m exceptions for walking from a hub to transit and from transit to the destination.
- Moved each participant's departure-location search into a clearly labeled full-width row instead of placing it beside the personal-car checkbox.
- Updated carpool assignment logic to honor explicit driver `pickup_passengers` selections and retain legacy `share_ride` compatibility.
- Increased walking-distance penalty in route scoring so longer walking legs are not selected for negligible arrival-time gains.
- Limited origin, destination, direct, transfer, and total itinerary walking to 300 metres.
- Corrected `arrive_by` ranking to sample departure times and select the latest feasible itinerary instead of returning routes from the beginning of the search window.
- Reduced arrival-by sampling from 10-minute scans to four one-hour samples and fixed sampled start times being ignored, preventing planning requests from exceeding the PHP execution limit.
- Pruned candidate and destination evaluation to stops within the 300 metre walking limit, avoiding full reachable-stop scans for every participant and meeting point.
- Moved planning to a database-backed queued run so the HTTP request returns immediately for large groups.
- Added per-participant progress tracking and a polling progress endpoint.
- Added a participant loader with progress bar and individual route statuses to the map interface.
- Aligned the database queue retry window with the 300-second planning job and added explicit failed-run reporting for worker timeouts.
- Added frontend progress-request validation and a ten-minute timeout message instead of an indefinite loader.
- Made planning progress explicitly non-cacheable and added immediate processing statuses so polling cannot remain on a stale queued response.
- Fixed the loader helpers being scoped inside the map-marker factory, which prevented the calculate handler from starting progress polling.
- Reduced timetable memory pressure by streaming active stop times through a feed-qualified `stop_times`/`trips` join, avoiding materialization of all trip IDs and a large `whereIn` parameter list.
- Changed timetable connection generation to stream SQL-built adjacent connections in departure order, eliminating the full PHP connection array, its sort-time copy, and oversized cache writes.
- Added geographic centrality to hub ranking: eligible candidates within 10 minutes of the earliest hub arrival are ranked by their total distance to all participant origins, preventing a fixed or ordered hub from dominating when the participant group is geographically distributed.
- Added a Montbéliard fixture regression verifying that distributed participants do not receive Acropole as an automatic fake hub.
- Streamlined meeting point selection: instead of retaining multiple candidate hubs, `MeetingPointFinder` now evaluates hub candidates and retains only the single best hub (earliest group arrival at destination, then least walking).
- Restricted options to exactly two outcomes: exactly one meeting point option (hub convergence or carpool) followed by one direct independent journey option, simplifying choices and avoiding redundant results.
- Excluded car owners from transit hub convergence: car owners are skipped during candidate hub reachability in `ItineraryPlanner` and drive directly to destination in hub strategies, ensuring personal car owners receive car transport only.
- Resolved actual transit line names for hub-to-destination group legs: replaced the generic "Ligne de transit collective" label in `MeetingPointFinder` by walking the CSA predecessor tree backwards to identify the real GTFS route, mode (train/bus), and line color.
- Interactive participant location selector: replaced the static coordinates label in participant cards with a searchable bus stop input with dropdown selection matching `bootstrapStops`, while syncing coordinates, markers, and map view.
- Driver convergence to meeting point (Problem 1): in the meeting point option, drivers (and any passengers they pick up) now drive to the meeting point hub first, meeting the other participants before taking the group leg together to the final destination.
- Carpool passengers ride with driver without public transit (Problem 2): passengers with shared rides are picked up directly at their departure location by the driver and travel entirely via car (`car_passenger` mode) without public transit legs to the meeting point or destination.
- Driver-specific passenger selection for multiple drivers (Problem 3): replaced the ambiguous generic "Share ride" checkbox on passenger cards with dynamic driver-specific passenger selection options ("Share ride with Passenger A", "Share ride with Passenger B") on each driver's card, allowing clear many-to-many driver-passenger assignment.
- Removed Optymo velo and car rental services (Problem 4): removed all GBFS shared car (`gbfs_car`, `car_share`) and bike rental (`bike`, `velo`) logic, commands (`ImportGbfs`), models, views, and test suites, focusing the platform strictly on urban buses, regional trains, and personal cars/carpools.
- Maintained full test suite and code style: all PHPUnit tests pass, Laravel Pint cleanly applied, and production frontend assets rebuilt with Vite.

### Phase 2: Static public places from Montbéliard and Belfort

**Dynamic candidate generation removed:**
- Deleted `App\Console\Commands\BuildCandidates` and its `php artisan mobility:build-candidates` command.
- Removed `Artisan::call('mobility:build-candidates')` from `ImportGtfs::handle()` and the `Artisan` facade import.
- Deleted the `tests/Fixtures/gtfs/hubs` fixture (GTJSON with Hub Alpha/Beta/Gamma) used for dynamic generation testing.
- Deleted `tests/Feature/HubSelectionTest` (tested dynamic generation scenarios).

**Static meeting points from `candidates.json`:**
- The curated `storage/app/data/candidates.json` contains public places from Montbéliard and Belfort (e.g., Gare de Montbéliard, Gare de Belfort, Place Corbis, Place de la Halle, Audincourt Temple, Valentigney Centre, Acropole, etc.).
- These are the meeting point catalog — imported via `mobility:import-gtfs` into `candidate_points` via `CandidatePoint::updateOrCreate`.
- The system already matches static meeting points with transit: `computeGroupLegFromHub()` finds the best transit route from stops near the meeting point to stops near the destination.

**Performance improvements retained:**
- Added reachability-based ranking in `MeetingPointFinder::find()`: candidates are ranked by how many independent participants can reach them (descending), then by distance to the group centroid. This prioritizes valid meeting points over distant ones.
- Added evaluation fallback: if the top `mobility.max_candidate_evaluations` (default 50) candidates don't produce hub strategies, evaluation continues up to `totalEvalLimit` (`max(100, max_candidate_evaluations × 4)` = 200 by default). This ensures meeting points are found even when the closest candidates lack valid transit to destinations.
- Removed spatial pruning from `ItineraryPlanner::reach()` so `candidates_reach` includes all reachable candidates; reachability counting is now done in `find()`.
- Added new config option `mobility.max_candidate_evaluations` (default 50, env `MOBILITY_MAX_CANDIDATE_EVALUATIONS`).

**Validation after dynamic generation removal:**
- 23 PHPUnit tests and 117 assertions pass; Laravel Pint reports no style issues.

### Dead data-path cleanup

- Removed the car-park import from `mobility:import-gtfs`; `car_parks.json` is no longer read or persisted.
- Removed the `mobility:import-car-parks` Overpass command and its feature tests.
- Removed the `CarPark`, `BikeStation`, and `CarShareStation` models.
- Added a migration dropping the `car_parks`, `bike_stations`, and `car_share_stations` tables; the migration recreates their schemas on rollback.
- Removed the unused `bike` and `shared_car` travel modes from `TravelTimeProvider`, `HaversineTravelTime`, and `LegBuilder`, along with `bike_speed_kmh` and the unused GBFS configuration block.
- Removed the unused `candidates`, `car_parks`, and `bike_stations` keys from the bootstrap payload; the frontend only consumed `places`, `stops`, and `bounding_box`.
- Retired the static matrix: deleted `mobility:build-matrix`, `BuildStaticMatrix`, `TransitiveClosure`, its unit test, the `DemoScenarioSeeder` call, and `storage/app/data/static_matrix.json`.
- Deleted the now-unused `storage/app/data/car_parks.json` artifact.
- Kept `candidates.json` as the meeting-point hub catalog and `places.json` as the destination list; both remain in active use.
- Noted that `Plan.md` remains the original specification; its static-matrix acceptance item is no longer implemented.
- Validation after cleanup: 23 PHPUnit tests and 117 assertions pass, Laravel Pint reports no style issues, and the new migration applies cleanly.

### Phase 1: Travel-time hub metrics, Pareto selection, phantom-car fix, pickup limits, reverse CSA

- Replaced geometric `candidateCentralityMeters` ranking with travel-time-based Pareto front ranking using `arrival_sec`, `sum_travel_to_hub_sec`, `max_travel_to_hub_sec`, `total_wait_min`, `max_wait_min`, and `total_walking_m`; hubs are now non-dominated multi-objective options.
- Return up to 3 Pareto-optimal hub options plus the direct fallback (max 4 options), with spatial diversity enforced (minimum 1 km separation); update `tests/Feature/PlanApiTest.php` option count from `<= 3` to accept 3–4.
- Fixed phantom car fallback: `computeGroupLegFromHub()` now returns `null` when no participant owns a car, instead of inventing a `Navette / Covoiturage final` car leg; group leg now reports real transit `line`, `mode`, and `color` via CSA predecessor chain, with `transfers` count.
- Optimized carpool pickup order: `resolveCarpoolAssignments()` now uses nearest-neighbour ordering + 2-opt routing, and enforces `max_detour_min` and `max_wait_min` from `config/mobility.php`; waiting is interpreted as passenger wait at origin.
- Replaced hourly `findBestReach` sampling for `arrive_by` with reverse CSA `ConnectionScan::latestDeparture` via new `ItineraryPlanner::latestReach()`; `ItineraryPlanner::reach()` now returns `start_time_sec` for correct duration calculation.
- Added `start_time_sec` to `ItineraryPlanner::reach()` return value; added `ItineraryPlanner::latestReach()` using reverse connection scan.
- Documented every modification in `changes.md`.

## Planned from now

The following improvements are the next work items and will be appended here as they are completed:

1. Run browser-level smoke checks against the locally served application.
2. Compact predecessor and connection representations further if larger feeds or concurrent workloads exceed the current memory envelope.
3. Review routing performance and add indexes or narrower queries if production-scale profiling identifies bottlenecks.
