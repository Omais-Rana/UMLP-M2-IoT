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

## Planned from now

The following improvements are the next work items and will be appended here as they are completed:

1. Run browser-level smoke checks against the locally served application.
2. Compact predecessor and connection representations further if larger feeds or concurrent workloads exceed the current memory envelope.
3. Review routing performance and add indexes or narrower queries if production-scale profiling identifies bottlenecks.
