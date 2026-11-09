<!DOCTYPE html>
<html lang="en" class="h-full bg-[#FDFDFD]">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title id="pageTitle">Smart Mobility — Multimodal Group Meeting Planner (Belfort & Montbéliard)</title>

    <!-- Google Fonts: Inter, Newsreader, JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">

    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Inter', sans-serif;
            color: #111827;
            background-color: #FDFDFD;
        }

        .font-serif-editorial {
            font-family: 'Newsreader', Georgia, serif;
        }

        .font-mono-tag {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #F3F4F6;
        }
        ::-webkit-scrollbar-thumb {
            background: #D1D5DB;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #9CA3AF;
        }

        /* Sidebar transition styling */
        #leftSidebar {
            transition: margin-left 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        #leftSidebar.collapsed {
            margin-left: -440px;
        }

        @media (max-width: 1023px) {
            #leftSidebar.collapsed {
                margin-left: -400px;
            }
        }

        @media (max-width: 768px) {
            #leftSidebar.collapsed {
                margin-left: -100%;
            }
        }
    </style>
</head>

<body class="h-full flex flex-col overflow-hidden text-[#111827]">

    <!-- Top Header Bar -->
    <header class="h-16 border-b border-[#E5E7EB] bg-white px-5 sm:px-6 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="w-3.5 h-3.5 rounded-full bg-[#FF5722] shadow-[0_0_10px_rgba(255,87,34,0.6)]"></span>
                <span class="font-display font-bold text-lg tracking-tight text-[#111827]">Smart<span class="text-[#FF5722]">.Mobility</span></span>
            </div>
            <span class="hidden md:inline-block text-xs uppercase tracking-wider font-mono-tag text-[#4B5563] bg-[#E5E7EB] px-2.5 py-0.5 rounded-full">
                Belfort — Montbéliard
            </span>
        </div>

        <div class="flex items-center gap-3">
            <!-- Language Switcher (EN / FR) -->
            <div class="flex items-center bg-[#E5E7EB] p-0.5 rounded-lg text-xs font-mono-tag" role="group" aria-label="Language switcher">
                <button id="langEnBtn" type="button" class="px-2.5 py-1 rounded-md font-bold transition-all bg-white text-[#111827] shadow-sm">
                    EN
                </button>
                <button id="langFrBtn" type="button" class="px-2.5 py-1 rounded-md font-medium text-[#4B5563] hover:text-[#111827] transition-all">
                    FR
                </button>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="flex-1 flex overflow-hidden relative">

        <!-- Left Control Panel (Collapsible) -->
        <aside id="leftSidebar" class="w-full md:w-[400px] lg:w-[440px] bg-white border-r border-[#E5E7EB] flex flex-col z-20 shrink-0 shadow-lg relative">
            <div class="flex-1 overflow-y-auto p-5 space-y-5">

                <!-- Destination Card -->
                <div class="bg-[#FDFDFD] border border-[#E5E7EB] rounded-2xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <label id="destLabel" class="text-xs font-bold uppercase tracking-wider text-[#4B5563] font-mono-tag flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                            Final Destination
                        </label>
                        <span id="destCoordBadge" class="text-[11px] font-mono-tag text-[#4B5563] bg-[#E5E7EB] px-2 py-0.5 rounded">
                            47.4957, 6.8040
                        </span>
                    </div>

                    <div class="space-y-2">
                        <!-- Searchable Destination Combobox -->
                        <div class="relative">
                            <div class="relative flex items-center">
                                <span class="absolute left-3 text-gray-400 pointer-events-none">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </span>
                                <input id="inputSearchDestination" type="text" autocomplete="off" placeholder="Search a bus stop or landmark..." value="🎓 UMLP Montbéliard (CAMPUS)" class="w-full text-sm font-medium border border-[#E5E7EB] rounded-lg pl-9 pr-8 py-2.5 bg-white text-[#111827] focus:border-[#FF5722] focus:ring-1 focus:ring-[#FF5722] outline-none">
                                <button id="btnClearDestSearch" type="button" class="absolute right-2.5 text-gray-400 hover:text-gray-600 hidden text-sm font-bold" title="Clear">&times;</button>
                            </div>

                            <!-- Dropdown Results Menu -->
                            <div id="destSearchResults" class="hidden absolute top-full left-0 right-0 mt-1 max-h-64 overflow-y-auto bg-white border border-[#E5E7EB] rounded-xl shadow-xl z-50 divide-y divide-gray-100 text-xs">
                                <!-- Populated dynamically by map.js -->
                            </div>
                        </div>

                        <p id="destHint" class="text-[11px] text-[#4B5563] flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-[#FF5722]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span>Drag the red marker or click the map to relocate destination.</span>
                        </p>
                    </div>
                </div>

                <!-- Timing & Objective Card -->
                <div class="bg-[#FDFDFD] border border-[#E5E7EB] rounded-2xl p-4 shadow-sm space-y-3">
                    <label id="timingLabel" class="text-xs font-bold uppercase tracking-wider text-[#4B5563] font-mono-tag block">
                        Meeting Date & Time
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <span id="dateLabel" class="text-[11px] text-[#4B5563] block mb-1">Date</span>
                            <input id="inputDate" type="date" value="2026-10-27" min="{{ $bootstrap['feed_meta']['valid_from'] }}" max="{{ $bootstrap['feed_meta']['valid_until'] }}" class="w-full text-xs font-mono-tag border border-[#E5E7EB] rounded-lg p-2 bg-white focus:border-[#FF5722] outline-none">
                        </div>
                        <div>
                            <span id="timeLabel" class="text-[11px] text-[#4B5563] block mb-1">Time</span>
                            <input id="inputTime" type="time" value="08:30" class="w-full text-xs font-mono-tag border border-[#E5E7EB] rounded-lg p-2 bg-white focus:border-[#FF5722] outline-none">
                        </div>
                    </div>

                    <!-- Depart at / Arrive by Segmented Button -->
                    <div class="grid grid-cols-2 gap-1.5 p-1 bg-[#E5E7EB] rounded-lg">
                        <button id="btnTimeTypeDepart" type="button" class="text-xs font-medium py-1.5 rounded-md bg-white text-[#111827] shadow-sm transition-all" data-value="depart_at">
                            Depart at
                        </button>
                        <button id="btnTimeTypeArrive" type="button" class="text-xs font-medium py-1.5 rounded-md text-[#4B5563] hover:text-[#111827] transition-all" data-value="arrive_by">
                            Arrive by
                        </button>
                    </div>
                </div>

                <!-- Participants Section -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-[#4B5563] font-mono-tag flex items-center gap-1.5">
                            <span id="participantsHeaderTitle">Participants (<span id="participantCount">0</span>)</span>
                        </h2>
                        <button id="btnAddParticipant" type="button" class="text-xs font-semibold text-[#FF5722] hover:text-[#E64A19] flex items-center gap-1 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            <span id="btnAddText">Add</span>
                        </button>
                    </div>

                    <p id="participantHint" class="text-[11px] text-[#4B5563]">
                        Tip: <em>Click directly on the map</em> to set a participant departure point.
                    </p>

                    <!-- Dynamic List of Participant Cards -->
                    <div id="participantsList" class="space-y-3">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Error Box -->
                <div id="errorBox" class="hidden p-3.5 bg-red-50 border border-red-200 rounded-xl text-xs text-red-700 space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span id="errTitle">Planning Error</span>
                    </div>
                    <p id="errorMessage" class="text-[11px] text-red-600"></p>
                </div>

            </div>

            <!-- Bottom CTA Bar -->
            <div class="p-4 border-t border-[#E5E7EB] bg-white space-y-2">
                <button id="btnCalculate" type="button" class="w-full py-3 px-4 rounded-xl font-display font-semibold text-sm bg-[#FF5722] hover:bg-[#E64A19] text-white shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2">
                    <svg id="calcIcon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                    </svg>
                    <svg id="calcSpinner" class="hidden animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span id="calcText">Calculate Optimal Route</span>
                </button>
            </div>
        </aside>

        <!-- Center: Leaflet Map Canvas Container -->
        <main class="flex-1 relative h-full min-w-0">
            <div id="map" class="w-full h-full"></div>

            <!-- Left Sidebar Collapse/Expand Toggle Button -->
            <button id="btnToggleLeftSidebar" type="button" class="absolute top-4 left-4 z-[450] bg-white/95 backdrop-blur-md border border-[#E5E7EB] hover:border-[#FF5722] rounded-xl py-2 px-2.5 text-gray-700 hover:text-[#FF5722] shadow-md transition-all flex items-center justify-center cursor-pointer" title="Toggle controls">
                <svg id="leftChevronIcon" class="w-4 h-4 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <!-- Floating Map Legend (Bottom-Left) -->
            <div class="absolute bottom-4 left-4 z-[400] bg-white/95 backdrop-blur-md border border-[#E5E7EB] rounded-xl p-3 shadow-md text-xs space-y-1.5 hidden sm:block">
                <div id="legendHeader" class="font-bold text-[11px] uppercase tracking-wider text-[#4B5563] font-mono-tag">Mode Legend</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px]">
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#FF5722] rounded-full"></span> <span id="legCar">Personal Car</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#FB923C] border-dashed border-t-2 rounded-full"></span> <span id="legCarpool">Carpool (Passenger)</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#7C3AED] rounded-full"></span> <span id="legTrain">TER Train</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#2563EB] rounded-full"></span> <span id="legBus">Urban Bus (Optymo / évolitY)</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#059669] rounded-full"></span> <span id="legBike">Shared Bike</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-3.5 h-1 bg-[#9CA3AF] border-dashed border-t-2 rounded-full"></span> <span id="legWalk">Walking</span></div>
                </div>
            </div>

            <!-- Floating Button to Re-open Right Panel when Results exist but panel is closed -->
            <button id="btnOpenResultsFloating" type="button" class="hidden absolute top-4 right-4 z-[450] bg-white/95 backdrop-blur-md border border-[#E5E7EB] hover:border-[#FF5722] rounded-xl py-2 px-3.5 text-xs font-semibold text-[#111827] hover:text-[#FF5722] shadow-md transition-all flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-[#FF5722] animate-pulse"></span>
                <span id="floatingResultsText">View Options</span>
                <svg class="w-4 h-4 text-[#FF5722]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </main>

        <!-- Right Side Panel (Results & Options) -->
        <aside id="rightSidebar" class="hidden w-full md:w-[420px] lg:w-[460px] bg-white border-l border-[#E5E7EB] flex flex-col z-20 shrink-0 shadow-xl transition-all duration-300">
            <!-- Header & Option Tabs -->
            <div class="border-b border-[#E5E7EB] px-5 py-3.5 bg-[#F9FAFB] flex items-center justify-between gap-2 shrink-0">
                <div class="flex items-center gap-2">
                    <span id="resultsDrawerTitle" class="font-display font-bold text-sm text-[#111827]">Meeting Options</span>
                </div>
                <button id="btnCloseResults" type="button" class="text-xs text-[#4B5563] hover:text-[#111827] px-2.5 py-1 rounded-md border border-[#E5E7EB] bg-white flex items-center gap-1 hover:border-gray-300 transition-colors shadow-sm" title="Close panel">
                    <span id="closeDrawerText">Close</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Option Switcher Sub-header -->
            <div class="px-5 py-2.5 bg-white border-b border-gray-100 flex items-center gap-1.5 overflow-x-auto shrink-0" id="optionTabs">
                <!-- Populated dynamically by JS -->
            </div>

            <!-- Scrollable Results Body -->
            <div id="resultsBody" class="flex-1 p-5 overflow-y-auto space-y-4">
                <!-- Populated dynamically by JS -->
            </div>
        </aside>

    </div>

    <!-- Inject Bootstrap Data for Client Script -->
    <script>
        window.MISC_BOOTSTRAP = @json($bootstrap);
    </script>
</body>
</html>
