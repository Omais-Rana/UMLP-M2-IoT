# Build Instructions: Multimodal Group Meeting Planner (Belfort and Montbéliard)

## 1. What to build

A **Laravel 13** web application with a map of **Belfort** and **Montbéliard**.

1. The user clicks the map to add **participants** (a named starting point each).
2. Each participant can be flagged as having a **personal car**. A car owner chooses: **Park it**, **Pick up others**, or **Let the planner decide**.
3. The user chooses the **final destination** and a **date and time**, then clicks **Calculate**.
4. The system returns the **best meeting point(s)** and, for each participant, the best **multimodal, multi-leg itinerary** up to the destination.
5. Results are drawn on the map and listed per participant.

Constraints: only the two cities; multimodal data from **GTFS** (timetables) and **GBFS** (shared bikes and cars); all data is **hardcoded** (bundled files, no live calls).

---

## 2. User interface

**Map page (single page):**
- Leaflet map with OpenStreetMap tiles, centered on the Belfort-Montbéliard area, restricted to the two cities' bounding box.
- **Add participant:** click the map, a form asks name, "has personal car" (checkbox), and for participants without a car offers an optional **Share ride** checkbox. Drivers have unlimited capacity.
- **Edit / move / delete** a participant (marker drag, popup buttons).
- **Destination:** a special marker (click map or pick from a list of hardcoded places).
- **Date and time** inputs, with a choice of "depart at" or "arrive by".
- **Calculate** button; loading state; clear error messages (no service on this date, point outside the area, no route found).
- **Results panel:** top 3 meeting options, selectable. For the selected option: per-participant timeline (legs with mode icon, line name, stop names, times), waiting times, and a short explanation of car decisions (for example: "Alice picks up Robin at Gare de Belfort, detour +4 min, wait 3 min").
- **Map rendering:** polylines per participant colored by mode (walk, bus, train, bike, car, shared car), meeting/pickup points highlighted, destination marker.

---

## 3. Data to bundle (in `storage/app/data/`)

| File | Content |
|---|---|
| Optymo GTFS zip | Belfort urban buses |
| évolitY GTFS zip | Montbéliard urban buses |
| TER/SNCF GTFS (filtered to the area) | Trains between Belfort, Montbéliard and the Belfort-Montbéliard TGV station |
| Optymo bike-sharing snapshot | Station locations (and a fixed availability snapshot) |
| Optymo Autopartage GBFS snapshot | Shared-car stations |
| `car_parks.json` | Car parks and park-and-ride sites: id, name, lat, lon, `near_stop_id` |
| `candidates.json` | Candidate meeting points (about 20-40): id, name, lat, lon, `car_accessible`, `has_parking`, `transit_hub` |
| `places.json` | Selectable destinations |

Rules:
- Use the **most recent** GTFS versions and make sure `calendar` / `calendar_dates` cover the dates the app accepts. Store the snapshot date and show it in the page footer.
- Reject requests whose date is outside the feeds' validity range.
- Generate **walking transfers** (stop to stop, across feeds) for stops closer than 400 m, and between stops and bike/shared-car stations.

---

## 4. Configuration constants (`config/mobility.php`)

| Key | Default |
|---|---|
| `walk_speed_kmh` | 4.8 |
| `bike_speed_kmh` | 15 |
| `car_speed_urban_kmh` / `car_speed_interurban_kmh` | 35 / 70 |
| `detour_factor` (haversine to real distance) | 1.3 |
| `transfer_buffer_min` | 3 |
| `parking_time_min` | 7 |
| `walk_transfer_radius_m` | 400 |
| `max_walk_to_pickup_m` | 800 |
| `max_detour_min` (driver, per pickup) | 15 |
| `max_wait_min` (driver waiting for a passenger) | 10 |
| `top_n_results` | 3 |

Distance model: haversine times `detour_factor` divided by the mode speed. Keep it behind an interface (`TravelTimeProvider`) so it can later be replaced by a precomputed OSRM matrix.

---

## 5. Data model

**Database tables** (migrations): `stops`, `routes`, `trips`, `stop_times`, `calendar`, `calendar_dates`, `transfers` (generated), `bike_stations`, `car_share_stations`, `car_parks`, `candidate_points`. Index `stop_times(stop_id, departure_time)`, `stop_times(trip_id, stop_sequence)`, `trips(service_id)`.

**Request payload** (`POST /api/plan`):
```json
{
  "date": "2026-10-27",
  "time": "08:30",
  "time_type": "depart_at",
  "destination": { "name": "Destination", "lat": 47.64, "lon": 6.86 },
  "participants": [
    { "name": "Robin", "lat": 47.51, "lon": 6.80,
      "has_car": false, "share_ride": true },
    { "name": "Alice", "lat": 47.63, "lon": 6.86,
      "has_car": true },
  ]
}
```

**Validation rules (`PlanRequest`):** `share_ride` is optional for participants without cars and is rejected for drivers.

**Result structure:**
```json
{
  "options": [
    {
      "meeting_points": [ { "name": "...", "lat": 0, "lon": 0, "type": "hub|pickup" } ],
      "arrival_at_destination": "09:42",
      "itineraries": [
        { "participant": "Robin",
          "legs": [
            { "mode": "walk|bus|train|bike|shared_car|car|car_passenger",
              "from": {}, "to": {}, "depart": "", "arrive": "",
              "duration_min": 0, "distance_m": 0,
              "line": null, "trip_id": null, "stops": [], "geometry": [] }
          ],
          "wait_min": 0, "transfers": 0, "walking_m": 0 }
      ],
      "car_decisions": [ { "driver": "Alice", "mode": "pickup",
          "passengers": ["Robin"], "pickup": [ { "who": "Robin", "at": "...",
          "detour_min": 4, "wait_min": 3 } ] } ]
    }
  ]
}
```

---

## 6. Algorithms to implement

### 6.1 Static matrix and transitive closure (`TransitiveClosure`)
Used for the non-timetable network (walk, bike, drive) between nodes (stops, car parks, bike stations, candidates).

- Matrix cell `t[i][j]` = `{cost, route}`; `0` on the diagonal; `-1` means no arc.
- Initialize only direct arcs (sparse matrix).
- Repeat: scan all `i, j, k`; if `t[i][j]` and `t[j][k]` exist and either `t[i][k]` is `-1` or `t[i][j].cost + t[j][k].cost` is smaller, set `t[i][k]` to the sum and `route = route(i,j) + route(j,k)`.
- Stop when a full pass modifies nothing.
- Precompute with an Artisan command (`mobility:build-matrix`) and store as JSON or a table.

**Required test.** Input:
```
 0  1  8 -1
-1  0  5  2
 1 -1  0 -1
 3  2 -1  0
```
Expected closure:
```
 0  1  6  3
 5  0  5  2
 1  2  0  4
 3  2  7  0
```
Check the result against Floyd-Warshall in the test.

### 6.2 Transit earliest arrival (`ConnectionScan`)
- Input: origin node(s) with start times, a service date, and the day's connections (sorted by departure time).
- Output: earliest arrival time at every stop, with the predecessor (trip, boarding stop, or transfer) for rebuilding legs.
- Handle `calendar` + `calendar_dates`, transfer buffers, and generated walking transfers.
- Cache the prepared connection array per service date.
- Provide a `reverse` variant for "arrive by" requests.

### 6.3 Per-participant access (`ItineraryPlanner::reach`)
For each participant, compute earliest arrival at each node by combining:
- walking and biking to nearby stops (and bike stations),
- shared car from a car-share station,
- driving to a car park or park and ride (car owners in `park` mode) with `parking_time_min`,
- transit via `ConnectionScan`,
- direct walk / bike / drive to a node.
Keep the predecessor chain to rebuild legs.

### 6.4 Meeting point and strategy selection (`MeetingPointFinder`)
For each candidate meeting point `m`:
1. `t_i(m)` = earliest arrival of participant `i` at `m` (6.3).
2. `T_leave(m) = max_i t_i(m)`.
3. Group leg `m -> D`: transit starting at `T_leave(m)`, or by car if a pick-up car group is used (6.5).
4. `arrival(m)` = latest arrival at `D` over all participants.

Also evaluate **car strategies with on-the-way pickups** (6.5), which do not need a hub. Rank all strategies by the **latest arrival at `D`**; tie-break on total waiting, then total walking. Return the top 3 with their per-person itineraries. Provide an alternative objective (sum of arrival times) selectable by a request parameter.

### 6.5 Car logic (`CarAssignment`)
Each car owner has `car_mode`: `park`, `pickup`, `auto`; `free_seats` (number of passenger seats the driver offers, excluding the driver; chosen by the driver in the UI, validated as an integer from 1 to `max_free_seats`).

**Park:** drive to a car park (origin side or a park and ride near a station), add `parking_time_min`, continue as a non-car participant.

**Pickup (route-aware, decided by the system):**

1. **Pickup spots** for a passenger `p` = the union of:
   - `p`'s starting point (door pickup),
   - car-accessible nodes within `max_walk_to_pickup_m` of `p`,
   - candidate meeting points marked `car_accessible`,
   - car-accessible nodes along the driver's way: any node `x` with `detour(x) <= max_detour_min`.
2. **Detour of a spot** `x` for driver `d`: `drive(O_d, x) + drive(x, D) - drive(O_d, D)`.
3. **Timeline of a car run:** the driver starts at `O_d`; for each pickup in order, the driver arrives at the spot, waits until the passenger arrives (`t_p(spot)` from 6.3), then continues; the run ends at `D`, followed by `parking_time_min`.
4. **Constraints:** passengers per car at most `free_seats` of that driver; driver wait at any spot at most `max_wait_min`; detour at most `max_detour_min`; spot must be `car_accessible`.
5. **Search (small input, exact):** for each driver, enumerate subsets of passengers (size up to that driver's `free_seats`), pickup orders (permutations), and, per passenger, the best 3 spots by smallest detour. Compute the car run's arrival times. **Cut:** abandon a branch as soon as its partial arrival at any point is not better than the best complete solution found so far. With several drivers, enumerate the assignment of each passenger to one car or to "no car", with the same cut.
6. **Passengers not placed in a car** (free seats used up, constraints violated, or slower) travel to `D` by their best direct itinerary (transit, bike, walk).
7. **Legs produced:** driver: `car` legs between pickup spots and to `D`; passenger: legs to the spot (walk, bus, ...), then `car_passenger` to `D`. The last leg of a car group is the car alone: no public transport for its passengers.
8. **Comparison:** the result lists, per car owner, the decision (`park` or `pickup`), the pickup spots, detour minutes and wait minutes.

**Auto:** compute `park` and `pickup`, keep the one with the earlier final arrival at `D` for the whole group (tie-break on the driver's detour).

### 6.6 Output options per participant
For each participant return up to 3 non-dominated itineraries: fastest, fewest transfers, least walking. (The chosen option, which is part of the top-level group strategy, is flagged.)

---

## 7. Project structure

```
app/
  Console/Commands/
    ImportGtfs.php
    ImportGbfs.php
    BuildTransfers.php
    BuildStaticMatrix.php
  Models/ Stop, Route, Trip, StopTime, Calendar, CalendarDate,
          Transfer, BikeStation, CarShareStation, CarPark, CandidatePoint
  Services/Routing/
    TravelTimeProvider.php        (interface) + HaversineTravelTime.php
    TransitiveClosure.php
    ConnectionScan.php
    LegBuilder.php
  Services/Planning/
    MeetingPointFinder.php
    CarAssignment.php
    ItineraryPlanner.php
  Http/Controllers/ MapController.php, PlanController.php
  Http/Requests/PlanRequest.php
config/mobility.php
resources/views/map.blade.php
resources/js/map.js
storage/app/data/               (bundled datasets)
database/migrations/
database/seeders/DemoScenarioSeeder.php
tests/Unit/ tests/Feature/
```

---

## 8. Build order and acceptance criteria

1. **Setup:** Laravel 13 project, config file, migrations, folder for datasets. *Done when* migrations run and the config loads.
2. **GTFS/GBFS import:** commands load all feeds; generate transfers. *Done when* counts of stops, trips and stop_times are non-zero for every feed and a sample query returns a day's departures from one stop.
3. **Closure:** implement `TransitiveClosure` and its test. *Done when* the 4-node test passes and matches Floyd-Warshall.
4. **Connection scan:** earliest arrival from one origin to all stops. *Done when* a known Belfort to Montbéliard trip (train) returns the expected timetable arrival.
5. **Access legs and leg building:** walking, biking, driving, shared car, park and ride. *Done when* a participant at a known address returns a complete multi-leg itinerary to a stop and to a candidate.
6. **Meeting point finder (hub strategies):** *Done when* `POST /api/plan` returns top 3 options for 3 non-car participants.
7. **Car logic:** park, pickup with on-the-way spots, auto, several drivers, each with their own `free_seats`. *Done when* tests show: (a) a driver with a passenger located along the way picks them up with a small detour and the last leg is car-only; (b) a passenger whose detour exceeds `max_detour_min` is not picked up; (c) a car with `free_seats = 1` and two eligible passengers takes only one, and the other travels by transit; (d) two drivers with different `free_seats` share passengers correctly.
8. **Map UI:** markers, forms, car options, Calculate, results panel, colored polylines. *Done when* the full flow works from the browser on the demo scenario.
9. **Demo seeder and tests:** a seeded scenario with 4 participants (two in each city, one car owner in `auto` mode). Feature test for `POST /api/plan`; timing for 2, 5 and 10 participants.
10. **Optional:** simulation of progress along the itineraries (play button, random delay on one leg, show delay or advance for the others).

---

## 9. Tests to include

- **Unit:** `TransitiveClosure` (4-node example), `ConnectionScan` (small synthetic feed with a known transfer), `CarAssignment` (detour computation, free-seat limit, wait limit, cut gives the same result as full enumeration), `ModeCosts` / `TravelTimeProvider`.
- **Feature:** `POST /api/plan` happy path, date out of feed range, participant outside the area, no participants, car owner in each of the three modes.
- **Regression:** a fixed scenario whose top option and arrival time are stored and must not change.
