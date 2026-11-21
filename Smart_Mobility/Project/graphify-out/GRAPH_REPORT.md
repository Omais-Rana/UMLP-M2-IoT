# Graph Report - Project  (2026-11-21)

## Corpus Check
- 129 files · ~68,578 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 22 file(s) not represented in the graph (top: (none) 18, .example 1, .xml 1)

## Summary
- 971 nodes · 1502 edges · 75 communities (51 shown, 24 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 46 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `1ea99ce6`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Route Search History
- TestCase
- Carpool & Meeting Point Routing
- GTFS Calendar & Schedules
- PHP Dependencies & Autoloading
- MapController.php
- Transit Agency & Planning Runs
- GTFS Import & Feeds
- Agent & Coding Guidelines
- Carpool & Meeting Point Routing
- Planning & Map Controllers
- Frontend Build & Tailwind CSS
- Transit Stops & Routes Model
- CalendarDate
- Transitive Closure & Shortest Paths
- Authentication & User Management
- Illuminate\Console\Command
- Illuminate\Support\Facades\Artisan
- Authentication & User Management
- Agent & Coding Guidelines
- Architecture Best Practices / Depend on 
- Authentication & User Management
- Frontend Build & Tailwind CSS
- UI/UX Design System
- Advanced Query Best Practices / Combine 
- Cache Event Discovery During Production 
- Database Migrations
- Back Off Transient Failures / Batch Jobs
- Carpool and planning contract / Change L
- Controller / Controller
- Caching Best Practices / Configure Failo
- Add Indexes for Measured Query Patterns 
- Apply Global Scopes Sparingly / Cast Dat
- Blade and View Best Practices / blade-vi
- Add Context to Exception Classes / Choos
- Bound Work Inside the Task / Group Share
- Authentication & User Management
- About Laravel / Agentic Development
- Choose Between `cursor()` and `lazy()` /
- Fake HTTP Requests in Tests / Handle Err
- Assert the Delivery Mode / Dispatch Queu
- Keep Controllers Focused on HTTP Concern
- Agent & Coding Guidelines
- Convention and Style Best Practices / Fo
- Agent & Coding Guidelines
- Arrange, Act, Assert / Assert a Known Va
- Built-in Laravel Assertion Methods / How
- Database / Fakes, Mocks, and Determinism
- Common Errors / Global Fakes
- Assertions / Coverage
- Stop
- Configuration Best Practices / config
- File Layout / Grouping
- Data Providers / Each Test Makes Its Own
- Consistency First / How to Apply
- PHPUnit\Framework\TestCase / ExampleTest
- copilot-instructions.md
- PlanApiTest
- ProcessPlanningRun.php
- ConnectionScan
- Illuminate\Http\Request
- PlanRequest
- TransitiveClosure
- DemoScenarioTest
- GtfsNormalizationTest

## God Nodes (most connected - your core abstractions)
1. `RouteSearchController` - 58 edges
2. `ImportGtfs` - 28 edges
3. `TestCase` - 20 edges
4. `Stop` - 20 edges
5. `MeetingPointFinder` - 16 edges
6. `ItineraryPlanner` - 14 edges
7. `CarAssignment` - 12 edges
8. `TravelTimeProvider` - 12 edges
9. `PlanApiTest` - 11 edges
10. `Calendar` - 11 edges

## Surprising Connections (you probably didn't know these)
- `RouteSearchController` --inherits--> `Controller`  [EXTRACTED]
  reference/RouteSearchController.php → app/Http/Controllers/Controller.php
- `SharedVehicleController` --inherits--> `Controller`  [EXTRACTED]
  reference/SharedVehicleController.php → app/Http/Controllers/Controller.php
- `CarAssignmentTest` --references--> `CarAssignment`  [EXTRACTED]
  tests/Unit/CarAssignmentTest.php → app/Services/Planning/CarAssignment.php
- `DemoScenarioTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/DemoScenarioTest.php → tests/TestCase.php
- `GtfsNormalizationTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/GtfsNormalizationTest.php → tests/TestCase.php

## Import Cycles
- None detected.

## Communities (75 total, 24 thin omitted)

### Community 0 - "Route Search History"
Cohesion: 0.07
Nodes (6): {closure#19}(), {closure#22}(), {closure#25}(), {closure#34}(), {closure#4}(), RouteSearchController

### Community 1 - "TestCase"
Cohesion: 0.17
Nodes (6): ExampleTest, ImportCarParksTest, StopSearchTest, TestCase, ConnectionScanTest, TravelTimeProviderTest

### Community 2 - "Carpool & Meeting Point Routing"
Cohesion: 0.06
Nodes (11): BuildTransfers, AppServiceProvider, CarAssignment, {closure#3}(), ItineraryPlanner, MeetingPointFinder, PlanningDataset, HaversineTravelTime (+3 more)

### Community 3 - "GTFS Calendar & Schedules"
Cohesion: 0.06
Nodes (31): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}() (+23 more)

### Community 4 - "PHP Dependencies & Autoloading"
Cohesion: 0.04
Nodes (48): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+40 more)

### Community 5 - "MapController.php"
Cohesion: 0.14
Nodes (4): Controller, MapController, PlanController, StopSearchController

### Community 7 - "Transit Agency & Planning Runs"
Cohesion: 0.13
Nodes (4): Agency, StopTime, Transfer, Trip

### Community 9 - "Agent & Coding Guidelines"
Cohesion: 0.07
Nodes (26): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Deployment, Do Things the Laravel Way, Documentation Files, Foundational Context (+18 more)

### Community 10 - "Carpool & Meeting Point Routing"
Cohesion: 0.18
Nodes (20): addParticipant(), applyLanguage(), createDestinationIcon(), createMeetingPointIcon(), createParticipantIcon(), createPickupIcon(), displayResults(), escapeHtml() (+12 more)

### Community 11 - "Planning & Map Controllers"
Cohesion: 0.15
Nodes (6): BikeStation, CandidatePoint, CarPark, CarShareStation, PlanningRun, Shape

### Community 12 - "Frontend Build & Tailwind CSS"
Cohesion: 0.13
Nodes (20): devDependencies, concurrently@^10.0.3, laravel-vite-plugin@^3.1, tailwindcss@^4.0.0, @tailwindcss/vite@^4.0.0, vite@^8.0.0, optionalDependencies, @laravel/multiplex@^0.4.1 (+12 more)

### Community 15 - "Transitive Closure & Shortest Paths"
Cohesion: 0.12
Nodes (16): 1. What to build, 2. User interface, 3. Data to bundle (in `storage/app/data/`), 4. Configuration constants (`config/mobility.php`), 5. Data model, 6.1 Static matrix and transitive closure (`TransitiveClosure`), 6.2 Transit earliest arrival (`ConnectionScan`), 6.3 Per-participant access (`ItineraryPlanner::reach`) (+8 more)

### Community 17 - "Illuminate\Console\Command"
Cohesion: 0.27
Nodes (3): BuildStaticMatrix, ImportCarParks, ImportParkingSpotsCommand

### Community 19 - "Authentication & User Management"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 20 - "Agent & Coding Guidelines"
Cohesion: 0.17
Nodes (11): Edge cases, Glob mapping, Ground Rules (read before you start), Infer Conventions, Process, Step 0: Orient, Step 1: Predefined sweep, Step 2: Open-ended pass (+3 more)

### Community 21 - "Architecture Best Practices / Depend on "
Cohesion: 0.17
Nodes (11): Architecture Best Practices, Depend on Contracts at Boundaries, Extract Focused Business Operations, Follow Framework Conventions, Inject Required Dependencies, Specify a Deterministic Sort Order, Use Atomic Locks for Race Conditions, Use `Concurrency::run()` for Parallel Execution (+3 more)

### Community 22 - "Authentication & User Management"
Cohesion: 0.18
Nodes (11): Apply Cross-Site Request Forgery Protection, Audit Dependencies, Authorize Protected Actions, Bind Query Parameters, Control Mass Assignment, Encrypt Sensitive Attributes When Appropriate, Escape Output in Its Context, Keep Secrets Out of Application Code (+3 more)

### Community 23 - "Frontend Build & Tailwind CSS"
Cohesion: 0.18
Nodes (10): Basic Usage, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Import Syntax, Replaced Utilities, Spacing (+2 more)

### Community 24 - "UI/UX Design System"
Cohesion: 0.18
Nodes (10): Colors, Components, Composition, Guardrails, Layout, Motion, Overview, Sentinel - Better Business Decisions (+2 more)

### Community 25 - "Advanced Query Best Practices / Combine "
Cohesion: 0.20
Nodes (9): Advanced Query Best Practices, Combine Related Counts with Conditional Aggregates, Compare `whereHas()` with an `IN` Subquery, Consider a Correlated Subquery for Has-Many Ordering, Create Dynamic Relationships with a Subquery Foreign Key, Design Composite Indexes for the Query, Measure Two Simple Queries Against One Complex Query, Reuse Loaded Parent Models with `setRelation()` (+1 more)

### Community 26 - "Cache Event Discovery During Production "
Cohesion: 0.20
Nodes (9): Cache Event Discovery During Production Deployment, Dispatch Queued Notifications After Commit, Events and Notifications Best Practices, Implement `HasLocalePreference` on Notifiable Models, Queue Slow Notifications, Rely on Event Discovery, Route Notification Channels to Dedicated Queues, Use On-Demand Notifications for Non-User Recipients (+1 more)

### Community 27 - "Database Migrations"
Cohesion: 0.20
Nodes (9): Define Foreign-Key Constraints Deliberately, Design Indexes for Real Queries, Generate Migrations with Artisan, Keep Migrations Focused, Make Rollbacks Honest, Migration Best Practices, Mirror Defaults Only When Unsaved Models Need Them, Stage Changes That Affect Existing Rows (+1 more)

### Community 28 - "Back Off Transient Failures / Batch Jobs"
Cohesion: 0.20
Nodes (9): Back Off Transient Failures, Batch Jobs for Group Coordination, Configure Time-Based Retry Limits Deliberately, Handle Terminal Failure When Needed, Keep Reservation Time Longer Than Execution Time, Queue and Job Best Practices, Rate Limit External Calls, Use Horizon for Redis Queue Operations (+1 more)

### Community 29 - "Carpool and planning contract / Change L"
Cohesion: 0.20
Nodes (9): Carpool and planning contract, Change Log, Completed, Feed-qualified routing, GBFS and parking prototype, GTFS import behavior, GTFS normalization and import, Planned from now (+1 more)

### Community 31 - "Caching Best Practices / Configure Failo"
Cohesion: 0.22
Nodes (8): Caching Best Practices, Configure Failover Cache Stores in Production, Consider `Cache::flexible()` for Stale-While-Revalidate, Use `Cache::add()` for Atomic Conditional Writes, Use `Cache::memo()` to Avoid Redundant Hits Within an Execution, Use `Cache::remember()` for Cache-Aside Reads, Use Cache Tags to Invalidate Related Groups, Use `once()` for In-Process Memoization

### Community 32 - "Add Indexes for Measured Query Patterns "
Cohesion: 0.22
Nodes (8): Add Indexes for Measured Query Patterns, Count Relationships Without Loading Them, Database Performance Best Practices, Eager Load Relationships Before Iterating, Keep Queries Out of Blade Templates, Prevent Lazy Loading in Development, Process Large Data Sets Incrementally, Select Only Needed Columns

### Community 33 - "Apply Global Scopes Sparingly / Cast Dat"
Cohesion: 0.22
Nodes (8): Apply Global Scopes Sparingly, Cast Date and Time Attributes, Define Attribute Casts, Define Precise Relationship Types, Eloquent Best Practices, Keep Application Queries Model-Aware, Use Local Scopes for Reusable Queries, Use `whereBelongsTo()` for Relationship Queries

### Community 34 - "Blade and View Best Practices / blade-vi"
Cohesion: 0.25
Nodes (7): Blade and View Best Practices, Prefer Components for Explicit Interfaces, Return Blade Fragments for Partial Rendering, Share Compatible View Data with a View Composer, Share Parent Component Props with `@aware`, Use `$attributes->merge()` in Component Templates, Use `@pushOnce` for Per-Component Scripts

### Community 35 - "Add Context to Exception Classes / Choos"
Cohesion: 0.25
Nodes (7): Add Context to Exception Classes, Choose Where to Report and Render Exceptions, Define JSON Rendering for API Routes, Error Handling Best Practices, Mark Exceptions the Handler Should Not Report, Prevent Duplicate Reports of One Exception Instance, Throttle High-Volume Exception Reports

### Community 36 - "Bound Work Inside the Task / Group Share"
Cohesion: 0.25
Nodes (7): Bound Work Inside the Task, Group Shared Configuration, Prevent Unwanted Overlap, Restrict Tasks by Environment, Run a Task on One Server, Run Eligible Commands in the Background, Task Scheduling Best Practices

### Community 37 - "Authentication & User Management"
Cohesion: 0.25
Nodes (7): Endpoint Coverage, Endpoint Tests, How to Write the Test, Tenant Isolation, Test Authorization at the Policy Level, Testing Validation, Which Layer Owns Which Case

### Community 38 - "About Laravel / Agentic Development"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 39 - "Choose Between `cursor()` and `lazy()` /"
Cohesion: 0.29
Nodes (6): Choose Between `cursor()` and `lazy()`, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 40 - "Fake HTTP Requests in Tests / Handle Err"
Cohesion: 0.29
Nodes (6): Fake HTTP Requests in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Pool Independent Requests, Retry Only Safe Operations, Set Explicit Timeouts

### Community 41 - "Assert the Delivery Mode / Dispatch Queu"
Cohesion: 0.29
Nodes (6): Assert the Delivery Mode, Dispatch Queued Mail After Commit, Mail Best Practices, Queue Slow Mail Delivery, Separate Content and Delivery Tests, Use Markdown Mailables When They Fit

### Community 42 - "Keep Controllers Focused on HTTP Concern"
Cohesion: 0.29
Nodes (6): Keep Controllers Focused on HTTP Concerns, Organize Controllers Around Resources, Routing and Controller Best Practices, Scope Nested Bindings, Use Implicit Route Model Binding, Use Resource Routes for Resourceful Actions

### Community 43 - "Agent & Coding Guidelines"
Cohesion: 0.29
Nodes (5): Consistency First, Decision Rules, How to Apply, Laravel Best Practices, Rule Index

### Community 44 - "Convention and Style Best Practices / Fo"
Cohesion: 0.29
Nodes (6): Convention and Style Best Practices, Follow Project Naming Conventions, Keep Presentation Code Maintainable, Prefer Clear, Idiomatic Syntax, Use Utilities When They Clarify Intent, Write Comments That Explain Why

### Community 45 - "Agent & Coding Guidelines"
Cohesion: 0.29
Nodes (6): Add Cross-Field Validation After Base Rules, Express Conditional Rules Clearly, Extract Validation When It Improves the Boundary, Prefer Readable Rule Syntax, Use Only Intended Validated Data, Validation and Forms Best Practices

### Community 46 - "Arrange, Act, Assert / Assert a Known Va"
Cohesion: 0.29
Nodes (6): Arrange, Act, Assert, Assert a Known Value, Assert the Complete Result, Assertions, How to Find the Correct Assertion, Named Response Assertions

### Community 47 - "Built-in Laravel Assertion Methods / How"
Cohesion: 0.29
Nodes (3): Built-in Laravel Assertion Methods, How to Find Test Framework Features, Security Tests

### Community 48 - "Database / Fakes, Mocks, and Determinism"
Cohesion: 0.29
Nodes (7): Database, Fakes, Mocks, and Determinism, Framework Fakes, How to Isolate a Dependency, Mocking, Outbound HTTP Testing, Time and Randomness

### Community 49 - "Common Errors / Global Fakes"
Cohesion: 0.29
Nodes (6): Common Errors, Global Fakes, How to Find a Slow Test, How to Run the Suite in Parallel, Test Environment, Test Suite Performance

### Community 50 - "Assertions / Coverage"
Cohesion: 0.29
Nodes (6): Assertions, Coverage, Data and Determinism, Names and Structure, Reviewing Tests, Test Value

### Community 51 - "Stop"
Cohesion: 0.24
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), Route, Stop, {closure#1}(), {closure#2}()

### Community 52 - "Configuration Best Practices / config"
Cohesion: 0.33
Nodes (5): Configuration Best Practices, Name Repeated Domain Values, Protect Production Secrets, Read Environment Variables in Configuration Files, Use `App::environment()` for Environment Checks

### Community 53 - "File Layout / Grouping"
Cohesion: 0.33
Nodes (5): File Layout, Grouping, Naming and Structure, Naming Tests, Test Class and Methods

### Community 55 - "Data Providers / Each Test Makes Its Own"
Cohesion: 0.40
Nodes (4): Data Providers, Each Test Makes Its Own Data, Factories and Test Data, Record Construction

### Community 56 - "Consistency First / How to Apply"
Cohesion: 0.40
Nodes (5): Consistency First, How to Apply, Rule Index, Testing Best Practices, What to Test

### Community 69 - "ConnectionScan"
Cohesion: 0.35
Nodes (3): {closure#3}(), {closure#4}(), ConnectionScan

### Community 70 - "Illuminate\Http\Request"
Cohesion: 0.25
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

## Knowledge Gaps
- **253 isolated node(s):** `1. What to build`, `2. User interface`, `3. Data to bundle (in `storage/app/data/`)`, `4. Configuration constants (`config/mobility.php`)`, `5. Data model` (+248 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 476 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **24 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stop` connect `Stop` to `TestCase`, `Carpool & Meeting Point Routing`, `MapController.php`, `Route Search History`, `Transit Agency & Planning Runs`, `Planning & Map Controllers`, `Transit Stops & Routes Model`, `Illuminate\Console\Command`?**
  _High betweenness centrality (0.057) - this node is a cross-community bridge._
- **What connects `1. What to build`, `2. User interface`, `3. Data to bundle (in `storage/app/data/`)` to the rest of the system?**
  _253 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Route Search History` be split into smaller, more focused modules?**
  _Cohesion score 0.07344632768361582 - nodes in this community are weakly interconnected._
- **Why does `RouteSearchController` connect `Route Search History` to `Illuminate\Http\Request`, `MapController.php`, `Route Search History`?**
  _High betweenness centrality (0.051) - this node is a cross-community bridge._
- **Should `Carpool & Meeting Point Routing` be split into smaller, more focused modules?**
  _Cohesion score 0.05860805860805861 - nodes in this community are weakly interconnected._
- **Why does `ImportGtfs` connect `GTFS Import & Feeds` to `Illuminate\Console\Command`, `Transit Agency & Planning Runs`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Should `GTFS Calendar & Schedules` be split into smaller, more focused modules?**
  _Cohesion score 0.058469945355191254 - nodes in this community are weakly interconnected._