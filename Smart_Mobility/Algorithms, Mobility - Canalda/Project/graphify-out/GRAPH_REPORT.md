# Graph Report - Project  (2026-11-23)

## Corpus Check
- 132 files · ~59,175 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 23 file(s) not represented in the graph (top: (none) 19, .example 1, .xml 1)

## Summary
- 900 nodes · 1411 edges · 82 communities (51 shown, 31 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 68 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `e3d3cdbb`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- AppServiceProvider.php
- Illuminate\Foundation\Testing\RefreshDatabase
- Carpool & Meeting Point Routing
- Illuminate\Database\Schema\Blueprint
- PHP Dependencies & Autoloading
- App\Http\Controllers\PlanController
- SharedPlan
- GTFS Import & Feeds
- Agent & Coding Guidelines
- Carpool & Meeting Point Routing
- App\Http\Controllers\MapController
- Frontend Build & Tailwind CSS
- Transit Stops & Routes Model
- Illuminate\Database\Eloquent\Model
- PlanRequest
- User
- Illuminate\Database\Migrations\Migration
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
- bootstrap/app.php
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
- 2026_10_23_133919_create_transport_feed_metadata_tables.php
- Configuration Best Practices / config
- File Layout / Grouping
- Data Providers / Each Test Makes Its Own
- Consistency First / How to Apply
- PHPUnit\Framework\TestCase / ExampleTest
- copilot-instructions.md
- 0011_01_01_000000_create_users_table.php
- ProcessPlanningRun.php
- 0031_01_01_000002_create_jobs_table.php
- 0021_01_01_000001_create_cache_table.php
- 2026_10_22_132627_add_calendar_feed_service_unique_index.php
- 2026_10_24_155700_add_transfer_endpoint_feeds.php
- 2026_10_25_172500_add_calendar_date_uniqueness.php
- Stop
- 2026_11_23_093724_add_participants_to_shared_plans_table.php
- 2026_11_23_095054_add_expires_at_to_shared_plans_table.php
- StopSearchController

## God Nodes (most connected - your core abstractions)
1. `ImportGtfs` - 28 edges
2. `MeetingPointFinder` - 19 edges
3. `TestCase` - 18 edges
4. `ConnectionScan` - 18 edges
5. `Stop` - 18 edges
6. `ItineraryPlanner` - 17 edges
7. `SharedPlan` - 13 edges
8. `CarAssignment` - 12 edges
9. `displayResults()` - 11 edges
10. `PlanApiTest` - 11 edges

## Surprising Connections (you probably didn't know these)
- `CarAssignmentTest` --references--> `CarAssignment`  [EXTRACTED]
  tests/Unit/CarAssignmentTest.php → app/Services/Planning/CarAssignment.php
- `{closure#1}()` --references--> `Stop`  [EXTRACTED]
  app/Services/Planning/PlanningDataset.php → app/Models/Stop.php
- `HaversineTravelTime` --implements--> `TravelTimeProvider`  [EXTRACTED]
  app/Services/Routing/HaversineTravelTime.php → app/Services/Routing/TravelTimeProvider.php
- `{closure#2}()` --references--> `Route`  [EXTRACTED]
  app/Services/Planning/PlanningDataset.php → app/Models/Route.php
- `MapController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/MapController.php → app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (82 total, 31 thin omitted)

### Community 1 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.05
Nodes (15): BuildStaticMatrix, BuildTransfers, ImportCarParks, TransitiveClosure, DemoScenarioTest, ExampleTest, GtfsNormalizationTest, ImportCarParksTest (+7 more)

### Community 2 - "Carpool & Meeting Point Routing"
Cohesion: 0.06
Nodes (11): CarAssignment, {closure#2}(), {closure#3}(), {closure#2}(), ItineraryPlanner, MeetingPointFinder, PlanningDataset, ConnectionScan (+3 more)

### Community 3 - "Illuminate\Database\Schema\Blueprint"
Cohesion: 0.24
Nodes (11): {closure#1}(), {closure#10}(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+3 more)

### Community 4 - "PHP Dependencies & Autoloading"
Cohesion: 0.04
Nodes (48): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+40 more)

### Community 5 - "App\Http\Controllers\PlanController"
Cohesion: 0.25
Nodes (3): Controller, PlanController, SharePlanController

### Community 7 - "SharedPlan"
Cohesion: 0.19
Nodes (3): {closure#1}(), SharedPlan, SharedPlanFactory

### Community 9 - "Agent & Coding Guidelines"
Cohesion: 0.07
Nodes (26): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Deployment, Do Things the Laravel Way, Documentation Files, Foundational Context (+18 more)

### Community 10 - "Carpool & Meeting Point Routing"
Cohesion: 0.16
Nodes (23): addParticipant(), applyLanguage(), createDestinationIcon(), createMeetingPointIcon(), createParticipantIcon(), createPickupIcon(), displayResults(), escapeHtml() (+15 more)

### Community 12 - "Frontend Build & Tailwind CSS"
Cohesion: 0.13
Nodes (20): devDependencies, concurrently@^10.0.3, laravel-vite-plugin@^3.1, tailwindcss@^4.0.0, @tailwindcss/vite@^4.0.0, vite@^8.0.0, optionalDependencies, @laravel/multiplex@^0.4.1 (+12 more)

### Community 14 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.06
Nodes (16): Agency, BikeStation, CalendarDate, CandidatePoint, CarPark, CarShareStation, PlanningRun, Route (+8 more)

### Community 16 - "User"
Cohesion: 0.10
Nodes (4): User, UserFactory, DatabaseSeeder, DemoScenarioSeeder

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
Cohesion: 0.17
Nodes (11): Apply Cross-Site Request Forgery Protection, Audit Dependencies, Authorize Protected Actions, Bind Query Parameters, Control Mass Assignment, Encrypt Sensitive Attributes When Appropriate, Escape Output in Its Context, Keep Secrets Out of Application Code (+3 more)

### Community 23 - "Frontend Build & Tailwind CSS"
Cohesion: 0.18
Nodes (10): Basic Usage, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Import Syntax, Replaced Utilities, Spacing (+2 more)

### Community 24 - "UI/UX Design System"
Cohesion: 0.18
Nodes (10): Colors, Components, Composition, Guardrails, Layout, Motion, Overview, Sentinel - Better Business Decisions (+2 more)

### Community 25 - "Advanced Query Best Practices / Combine "
Cohesion: 0.22
Nodes (9): Advanced Query Best Practices, Combine Related Counts with Conditional Aggregates, Compare `whereHas()` with an `IN` Subquery, Consider a Correlated Subquery for Has-Many Ordering, Create Dynamic Relationships with a Subquery Foreign Key, Design Composite Indexes for the Query, Measure Two Simple Queries Against One Complex Query, Reuse Loaded Parent Models with `setRelation()` (+1 more)

### Community 26 - "Cache Event Discovery During Production "
Cohesion: 0.20
Nodes (9): Cache Event Discovery During Production Deployment, Dispatch Queued Notifications After Commit, Events and Notifications Best Practices, Implement `HasLocalePreference` on Notifiable Models, Queue Slow Notifications, Rely on Event Discovery, Route Notification Channels to Dedicated Queues, Use On-Demand Notifications for Non-User Recipients (+1 more)

### Community 27 - "Database Migrations"
Cohesion: 0.20
Nodes (9): Define Foreign-Key Constraints Deliberately, Design Indexes for Real Queries, Generate Migrations with Artisan, Keep Migrations Focused, Make Rollbacks Honest, Migration Best Practices, Mirror Defaults Only When Unsaved Models Need Them, Stage Changes That Affect Existing Rows (+1 more)

### Community 28 - "Back Off Transient Failures / Batch Jobs"
Cohesion: 0.22
Nodes (9): Back Off Transient Failures, Batch Jobs for Group Coordination, Configure Time-Based Retry Limits Deliberately, Handle Terminal Failure When Needed, Keep Reservation Time Longer Than Execution Time, Queue and Job Best Practices, Rate Limit External Calls, Use Horizon for Redis Queue Operations (+1 more)

### Community 29 - "Carpool and planning contract / Change L"
Cohesion: 0.20
Nodes (9): Carpool and planning contract, Change Log, Completed, Feed-qualified routing, GBFS and parking prototype, GTFS import behavior, GTFS normalization and import, Planned from now (+1 more)

### Community 30 - "bootstrap/app.php"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

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
Cohesion: 0.25
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

### Community 51 - "2026_10_23_133919_create_transport_feed_metadata_tables.php"
Cohesion: 0.25
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

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

### Community 67 - "0011_01_01_000000_create_users_table.php"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 69 - "0031_01_01_000002_create_jobs_table.php"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 75 - "Stop"
Cohesion: 0.21
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), Calendar, Stop

## Knowledge Gaps
- **239 isolated node(s):** `A. Validation & HTTP input`, `B. Controllers & routing`, `C. Authorization`, `D. Eloquent & models`, `E. Architecture & organization` (+234 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 430 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **31 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stop` connect `Stop` to `Illuminate\Foundation\Testing\RefreshDatabase`, `Carpool & Meeting Point Routing`, `App\Http\Controllers\MapController`, `Illuminate\Database\Eloquent\Model`?**
  _High betweenness centrality (0.030) - this node is a cross-community bridge._
- **What connects `A. Validation & HTTP input`, `B. Controllers & routing`, `C. Authorization` to the rest of the system?**
  _239 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Illuminate\Foundation\Testing\RefreshDatabase` be split into smaller, more focused modules?**
  _Cohesion score 0.05048076923076923 - nodes in this community are weakly interconnected._
- **Why does `ImportGtfs` connect `GTFS Import & Feeds` to `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Database\Eloquent\Model`?**
  _High betweenness centrality (0.028) - this node is a cross-community bridge._
- **Should `Carpool & Meeting Point Routing` be split into smaller, more focused modules?**
  _Cohesion score 0.05854049719326383 - nodes in this community are weakly interconnected._
- **Why does `ConnectionScan` connect `Carpool & Meeting Point Routing` to `AppServiceProvider.php`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Database\Eloquent\Model`?**
  _High betweenness centrality (0.025) - this node is a cross-community bridge._
- **Should `PHP Dependencies & Autoloading` be split into smaller, more focused modules?**
  _Cohesion score 0.04081632653061224 - nodes in this community are weakly interconnected._