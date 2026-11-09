// Smart Mobility Multimodal Group Meeting Planner Engine
document.addEventListener('DOMContentLoaded', () => {
    const bootstrap = window.MISC_BOOTSTRAP || {};
    const defaultBounds = bootstrap.bounding_box || {
        min_lat: 47.45,
        max_lat: 47.72,
        min_lon: 6.72,
        max_lon: 6.96
    };

    // Translations Dictionary (EN default, FR alternate)
    const i18n = {
        en: {
            page_title: "Smart Mobility — Multimodal Group Meeting Planner (Belfort & Montbéliard)",
            gtfs_valid: "GTFS Valid:",
            stops: "Stops:",
            btn_demo: "Demo Scenario (4 pers.)",
            final_dest: "Final Destination",
            dest_placeholder: "Select a bus stop or destination...",
            dest_custom: "Manual selection on map...",
            dest_hint: "Drag the red marker or click the map to relocate destination.",
            meeting_datetime: "Meeting Date & Time",
            date_label: "Date",
            time_label: "Time",
            depart_at: "Depart at",
            arrive_by: "Arrive by",
            participants_title: "Participants",
            add_participant: "Add",
            participant_hint: "Tip: Click directly on the map to set a participant departure point.",
            no_participants: "No participants added yet.<br>Click on the map or 'Add'.",
            name_placeholder: "Name",
            has_car: "Personal car",
            share_ride: "Share ride",
            err_title: "Planning Error",
            calc_btn: "Calculate Optimal Route",
            calc_running: "Calculating multimodal routes...",
            legend_title: "Mode Legend",
            leg_car: "Personal Car",
            leg_carpool: "Carpool (Passenger)",
            leg_train: "TER Train",
            leg_bus: "Urban Bus (Optymo / évolitY)",
            leg_bike: "Shared Bike",
            leg_walk: "Walking",
            results_title: "Meeting Options",
            option: "Option",
            close_drawer: "Close",
            floating_results: "View Options",
            toggle_controls: "Toggle controls",
            meeting_place: "Convergence Meeting Point",
            group_arrival: "Estimated Group Arrival",
            carpool_decision_title: "Carpool Decision:",
            carpool_picks_up: "picks up",
            carpool_at: "at",
            carpool_detour: "detour:",
            carpool_wait: "wait:",
            individual_itineraries: "Individual Itineraries",
            duration: "Duration:",
            wait: "Wait:",
            walk: "Walk:",
            transfers: "transfers",
            direct: "Direct",
            carpool_with: "Carpool with",
            val_no_participants: "Please add at least one participant to calculate the route.",
            val_invalid_dest: "Please specify a valid destination.",
            val_no_route: "No route found for this date and these participants.",
            network_err: "Server connection error. Please check your network.",
            marker_dest: "Destination:",
            marker_custom: "Custom point",
            marker_car_info: "Car",
            marker_no_car: "No car",
            marker_share_ride: "Share ride enabled",
            convergence_point: "Convergence point:",
            pickup_point: "Carpool Pickup:",
            midpoint_coordinates: "Meeting point coordinates:",
            route_color: "Route color:",
            no_midpoint: "No shared midpoint — participants travel independently",
            carpool_pickup_strategy: "Carpool pickup on route — destination convergence",
            show_route_details: "Show route details",
            hide_route_details: "Hide route details"
        },
        fr: {
            page_title: "Smart Mobility — Planificateur Multimodal de Rendez-vous (Belfort & Montbéliard)",
            gtfs_valid: "GTFS Valide :",
            stops: "Arrêts :",
            btn_demo: "Scénario Démo (4 pers.)",
            final_dest: "Destination Finale",
            dest_placeholder: "Sélectionner un arrêt de bus ou lieu...",
            dest_custom: "Sélection manuelle sur la carte...",
            dest_hint: "Glissez le marqueur rouge ou cliquez sur la carte pour déplacer.",
            meeting_datetime: "Date & Heure du Rendez-vous",
            date_label: "Date",
            time_label: "Heure",
            depart_at: "Partir à",
            arrive_by: "Arriver pour",
            participants_title: "Participants",
            add_participant: "Ajouter",
            participant_hint: "Astuce : Cliquez directement sur la carte pour positionner un participant.",
            no_participants: "Aucun participant ajouté.<br>Cliquez sur la carte ou sur 'Ajouter'.",
            name_placeholder: "Nom",
            has_car: "Voiture perso",
            share_ride: "Covoiturage",
            err_title: "Erreur de planification",
            calc_btn: "Calculer l'Itinéraire Optimal",
            calc_running: "Calcul multimodal en cours...",
            legend_title: "Légende Modes",
            leg_car: "Voiture personnelle",
            leg_carpool: "Covoiturage (Passager)",
            leg_train: "TER Train",
            leg_bus: "Bus Optymo / évolitY",
            leg_bike: "Vélo partagé",
            leg_walk: "Marche à pied",
            results_title: "Options de Rendez-vous",
            option: "Option",
            close_drawer: "Fermer",
            floating_results: "Voir les options",
            toggle_controls: "Masquer / afficher",
            meeting_place: "Lieu de convergence",
            group_arrival: "Arrivée estimée groupe",
            carpool_decision_title: "Décision de covoiturage :",
            carpool_picks_up: "prend en charge",
            carpool_at: "à",
            carpool_detour: "détour :",
            carpool_wait: "attente :",
            individual_itineraries: "Feuilles de route individuelles",
            duration: "Durée :",
            wait: "Attente :",
            walk: "Marche :",
            transfers: "correspondances",
            direct: "Direct",
            carpool_with: "Covoiturage avec",
            val_no_participants: "Veuillez ajouter au moins un participant pour calculer l'itinéraire.",
            val_invalid_dest: "Veuillez spécifier une destination valide.",
            val_no_route: "Aucun itinéraire trouvé pour cette date et ces participants.",
            network_err: "Erreur de connexion au serveur. Vérifiez votre réseau.",
            marker_dest: "Destination :",
            marker_custom: "Point personnalisé",
            marker_car_info: "Voiture",
            marker_no_car: "Sans voiture",
            marker_share_ride: "Covoiturage activé",
            convergence_point: "Point de convergence :",
            pickup_point: "Prise en charge covoiturage :",
            midpoint_coordinates: "Coordonnées du point de rendez-vous :",
            route_color: "Couleur de l'itinéraire :",
            no_midpoint: "Aucun point de rendez-vous partagé — trajets indépendants",
            carpool_pickup_strategy: "Ramassage covoiturage sur trajet — convergence à destination",
            show_route_details: "Afficher le détail du trajet",
            hide_route_details: "Masquer le détail du trajet"
        }
    };

    // Language State (English default)
    let currentLang = localStorage.getItem('smart_mobility_lang') || 'en';

    function t(key) {
        return i18n[currentLang]?.[key] || i18n['en'][key] || key;
    }

    function createMeetingPointIcon() {
        return L.divIcon({
            className: 'custom-meeting-point-icon',
            html: `
                <div style="background-color: #FF5722; width: 34px; height: 34px; border-radius: 9999px; border: 3px solid #FFFFFF; box-shadow: 0 4px 12px rgba(255, 87, 34, 0.5); display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 15px;">
                    M
                </div>
            `,
            iconSize: [34, 34],
            iconAnchor: [17, 17]
        });

    }

    function renderPlanningParticipants(items) {
        planningParticipants.innerHTML = items.map(item => {
            const status = item.status === 'completed' ? 'Complete' : item.status === 'processing' ? 'Planning…' : 'Waiting';
            const color = item.status === 'completed' ? 'text-emerald-600' : item.status === 'processing' ? 'text-[#FF5722]' : 'text-[#6B7280]';
            return `<div class="flex items-center justify-between text-xs">
                <span class="font-medium text-[#111827]">${escapeHtml(item.name)}</span>
                <span class="font-mono-tag ${color}">${status}</span>
            </div>`;
        }).join('');
    }

    async function waitForPlanning(planningId) {
        const startedAt = Date.now();
        const maxWaitMs = 10 * 60 * 1000;

        while (true) {
            const response = await fetch(`/api/plan/${planningId}/progress?poll=${Date.now()}`, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) {
                throw new Error('Unable to read planning progress.');
            }
            const progress = await response.json();
            const total = Math.max(1, progress.total_participants);
            const percent = Math.round((progress.completed_participants / total) * 100);
            planningProgressText.textContent = `${percent}%`;
            planningProgressBar.style.width = `${percent}%`;
            renderPlanningParticipants(progress.participants || []);

            if (progress.status === 'completed') return progress;
            if (progress.status === 'failed') throw new Error(progress.error || 'Planning failed.');
            if (Date.now() - startedAt > maxWaitMs) {
                throw new Error('Planning is taking too long. Check that the queue worker is running with a 300-second timeout.');
            }
            await new Promise(resolve => setTimeout(resolve, 750));
        }
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[character]));
    }

    function createPickupIcon() {
        return L.divIcon({
            className: 'custom-pickup-icon',
            html: `
                <div style="background-color: #F59E0B; width: 30px; height: 30px; border-radius: 9999px; border: 2.5px solid #FFFFFF; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.5); display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">
                    🚗
                </div>
            `,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });
    }

    function setLanguage(lang) {
        currentLang = lang;
        localStorage.setItem('smart_mobility_lang', lang);
        document.documentElement.lang = lang;
        applyLanguage();
    }

    function applyLanguage() {
        const isEn = currentLang === 'en';
        document.getElementById('langEnBtn').className = `px-2.5 py-1 rounded-md font-bold transition-all ${isEn ? 'bg-white text-[#111827] shadow-sm' : 'text-[#4B5563] hover:text-[#111827]'}`;
        document.getElementById('langFrBtn').className = `px-2.5 py-1 rounded-md font-bold transition-all ${!isEn ? 'bg-white text-[#111827] shadow-sm' : 'text-[#4B5563] hover:text-[#111827]'}`;

        document.getElementById('pageTitle').textContent = t('page_title');
        document.getElementById('destLabel').innerHTML = `<span class="w-2 h-2 rounded-full bg-red-500"></span> ${t('final_dest')}`;
        const inputSearch = document.getElementById('inputSearchDestination');
        if (inputSearch) inputSearch.placeholder = t('dest_placeholder');
        document.getElementById('destHint').innerHTML = `
            <svg class="w-3.5 h-3.5 text-[#FF5722]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>${t('dest_hint')}</span>
        `;
        document.getElementById('timingLabel').textContent = t('meeting_datetime');
        document.getElementById('dateLabel').textContent = t('date_label');
        document.getElementById('timeLabel').textContent = t('time_label');
        document.getElementById('btnTimeTypeDepart').textContent = t('depart_at');
        document.getElementById('btnTimeTypeArrive').textContent = t('arrive_by');
        document.getElementById('participantsHeaderTitle').innerHTML = `${t('participants_title')} (<span id="participantCount">${participants.length}</span>)`;
        document.getElementById('btnAddText').textContent = t('add_participant');
        document.getElementById('participantHint').innerHTML = `${t('participant_hint')}`;
        document.getElementById('errTitle').textContent = t('err_title');
        document.getElementById('calcText').textContent = t('calc_btn');
        document.getElementById('legendHeader').textContent = t('legend_title');
        document.getElementById('legCar').textContent = t('leg_car');
        document.getElementById('legCarpool').textContent = t('leg_carpool');
        document.getElementById('legTrain').textContent = t('leg_train');
        document.getElementById('legBus').textContent = t('leg_bus');
        document.getElementById('legBike').textContent = t('leg_bike');
        document.getElementById('legWalk').textContent = t('leg_walk');
        document.getElementById('resultsDrawerTitle').textContent = t('results_title');
        const closeDrawerText = document.getElementById('closeDrawerText');
        if (closeDrawerText) closeDrawerText.textContent = t('close_drawer');
        const floatingResultsText = document.getElementById('floatingResultsText');
        if (floatingResultsText) floatingResultsText.textContent = t('floating_results');
        const btnToggleLeftSidebarEl = document.getElementById('btnToggleLeftSidebar');
        if (btnToggleLeftSidebarEl) btnToggleLeftSidebarEl.title = t('toggle_controls');

        renderParticipantsList();
        renderParticipantMarkers();
        if (currentOptions.length > 0) {
            displayResults();
        }
    }

    // State
    let participants = [];
    let destination = {
        name: 'UMLP Montbéliard (CAMPUS)',
        lat: 47.49573,
        lon: 6.80400
    };
    let timeType = 'depart_at';
    let currentOptions = [];
    let selectedOptionIndex = 0;

    const participantColors = [
        '#2563EB', '#059669', '#7C3AED', '#DB2777', '#D97706', '#0891B2', '#4B5563'
    ];

    // Initialize Leaflet Map
    const mapCenter = [47.58, 6.86];
    const map = L.map('map', {
        center: mapCenter,
        zoom: 11,
        minZoom: 10,
        maxZoom: 18,
        zoomControl: false,
        maxBounds: [
            [defaultBounds.min_lat - 0.05, defaultBounds.min_lon - 0.05],
            [defaultBounds.max_lat + 0.05, defaultBounds.max_lon + 0.05]
        ]
    });
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // Standard OpenStreetMap tile layer
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    // Marker & Polyline Layer Groups
    const participantLayer = L.layerGroup().addTo(map);
    const destinationLayer = L.layerGroup().addTo(map);
    const routesLayer = L.layerGroup().addTo(map);
    const hubsLayer = L.layerGroup().addTo(map);

    // Custom Icon Creators
    function createDestinationIcon() {
        return L.divIcon({
            className: 'custom-dest-icon',
            html: `
                <div style="background-color: #EF4444; width: 34px; height: 34px; border-radius: 9999px; border: 3px solid #FFFFFF; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.5); display: flex; align-items: center; justify-content: center; color: white;">
                    <svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9" />
                    </svg>
                </div>
            `,
            iconSize: [34, 34],
            iconAnchor: [17, 17]
        });
    }

    function createParticipantIcon(name, color, hasCar) {
        const initials = name ? name.trim().split(' ').map(p => p[0]).join('').substring(0, 2).toUpperCase() : 'P';
        return L.divIcon({
            className: 'custom-participant-icon',
            html: `
                <div style="background-color: ${color}; width: 32px; height: 32px; border-radius: 9999px; border: 2.5px solid #FFFFFF; box-shadow: 0 4px 10px rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 11px; font-family: 'JetBrains Mono', monospace; position: relative;">
                    ${initials}
                    ${hasCar ? `<span style="position: absolute; bottom: -2px; right: -4px; background: #121212; border: 1.5px solid white; border-radius: 9999px; width: 14px; height: 14px; display: flex; align-items: center; justify-content: center; font-size: 8px;">🚗</span>` : ''}
                </div>
            `,
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });
    }

    let destMarker = null;
    function updateDestinationMarker() {
        destinationLayer.clearLayers();
        destMarker = L.marker([destination.lat, destination.lon], {
            icon: createDestinationIcon(),
            draggable: true
        }).addTo(destinationLayer);

        destMarker.bindPopup(`<strong>${t('marker_dest')}</strong> ${destination.name}<br><small class="text-gray-500">${destination.lat.toFixed(4)}, ${destination.lon.toFixed(4)}</small>`);

        destMarker.on('dragend', (e) => {
            const coord = e.target.getLatLng();
            destination.lat = parseFloat(coord.lat.toFixed(6));
            destination.lon = parseFloat(coord.lng.toFixed(6));
            destination.name = `${t('marker_custom')} (${destination.lat}, ${destination.lon})`;
            // Update coordinates badge only - header stays as "Final Destination"
            document.getElementById('destCoordBadge').textContent = `${destination.lat.toFixed(4)}, ${destination.lon.toFixed(4)}`;
            // Safely update the selectDestination element if it exists
            const destSelect = document.getElementById('selectDestination');
            if (destSelect) destSelect.value = 'custom';
        });

        document.getElementById('destCoordBadge').textContent = `${destination.lat.toFixed(4)}, ${destination.lon.toFixed(4)}`;
    }

    updateDestinationMarker();

    // Map Click Handler: Add participant or set destination
    map.on('click', (e) => {
        const lat = parseFloat(e.latlng.lat.toFixed(6));
        const lon = parseFloat(e.latlng.lng.toFixed(6));

        // Check if a custom destination select element exists and is set to 'custom'
        const destSelect = document.getElementById('selectDestination');
        const isCustomDestination = destSelect && destSelect.value === 'custom';

        // If custom destination mode is active AND the destination hasn't been manually set yet,
        // use the click position as the new destination. Otherwise, add a participant.
        // We check if destination lat is the initial default value (47.49573) to determine
        // if it's been customized; if lat is not the default, it's already been set.
        const isDefaultDestination = destination.lat === 47.49573 && destination.lon === 6.80400;

        if (isCustomDestination && !isDefaultDestination) {
            destination.lat = lat;
            destination.lon = lon;
            destination.name = `${t('marker_custom')} (${lat}, ${lon})`;
            updateDestinationMarker();
            return;
        }

        const nextIdx = participants.length + 1;
        addParticipant({
            name: `Participant ${nextIdx}`,
            lat: lat,
            lon: lon,
            has_car: false,
            share_ride: false
        });
    });

    // Participant Management
    function addParticipant(data) {
        const p = {
            id: 'p_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4),
            name: data.name || `Participant ${participants.length + 1}`,
            lat: data.lat || 47.6350,
            lon: data.lon || 6.8600,
            has_car: Boolean(data.has_car),
            share_ride: Boolean(data.share_ride),
            color: participantColors[participants.length % participantColors.length]
        };

        participants.push(p);
        renderParticipantsList();
        renderParticipantMarkers();
    }

    function removeParticipant(id) {
        participants = participants.filter(p => p.id !== id);
        renderParticipantsList();
        renderParticipantMarkers();
    }

    function renderParticipantMarkers() {
        participantLayer.clearLayers();

        participants.forEach(p => {
            const marker = L.marker([p.lat, p.lon], {
                icon: createParticipantIcon(p.name, p.color, p.has_car),
                draggable: true
            }).addTo(participantLayer);

            const carDesc = p.has_car
                ? `🚗 ${t('marker_car_info')}`
                : `🚶 ${t('marker_no_car')}${p.share_ride ? ` (${t('marker_share_ride')})` : ''}`;

            marker.bindPopup(`<strong>${p.name}</strong><br>${carDesc}`);

            marker.on('dragend', (e) => {
                const coord = e.target.getLatLng();
                p.lat = parseFloat(coord.lat.toFixed(6));
                p.lon = parseFloat(coord.lng.toFixed(6));
                renderParticipantsList();
            });
        });

        document.getElementById('participantCount').textContent = participants.length;
    }

    function renderParticipantsList() {
        const container = document.getElementById('participantsList');
        container.innerHTML = '';
        document.getElementById('participantCount').textContent = participants.length;

        if (participants.length === 0) {
            container.innerHTML = `
                <div class="p-4 border border-dashed border-[#E5E7EB] rounded-xl text-center text-xs text-[#4B5563]">
                    ${t('no_participants')}
                </div>
            `;
            return;
        }

        participants.forEach((p) => {
            const card = document.createElement('div');
            card.className = 'border border-[#E5E7EB] rounded-xl p-3 bg-white space-y-2.5 shadow-sm transition-all hover:border-gray-300';
            card.innerHTML = `
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 flex-1">
                        <span class="w-3 h-3 rounded-full shrink-0" style="background-color: ${p.color};"></span>
                        <input type="text" value="${p.name}" class="p-name-input text-xs font-semibold text-[#111827] border-b border-transparent hover:border-gray-300 focus:border-[#FF5722] outline-none flex-1 bg-transparent" placeholder="${t('name_placeholder')}">
                    </div>
                    <button type="button" class="btn-del text-gray-400 hover:text-red-500 text-sm px-1.5" title="Remove">&times;</button>
                </div>

                <div class="flex items-center justify-between text-xs text-[#4B5563] pt-1 border-t border-gray-100">
                    <label class="flex items-center gap-1.5 cursor-pointer select-none">
                        <input type="checkbox" ${p.has_car ? 'checked' : ''} class="chk-has-car rounded text-[#FF5722] focus:ring-[#FF5722]">
                        <span class="font-medium text-[#111827]">${t('has_car')}</span>
                    </label>
                    <span class="text-[11px] font-mono-tag text-gray-500">${p.lat.toFixed(4)}, ${p.lon.toFixed(4)}</span>
                </div>

                <div class="car-options ${p.has_car ? 'hidden' : ''} pt-1 border-t border-gray-100">
                    <label class="flex items-center gap-1.5 text-xs cursor-pointer select-none">
                        <input type="checkbox" class="chk-share-ride rounded text-[#FF5722] focus:ring-[#FF5722]" ${p.share_ride ? 'checked' : ''}>
                        <span class="font-medium text-[#111827]">${t('share_ride')}</span>
                    </label>
                </div>
            `;

            // Event bindings for this card
            const nameInput = card.querySelector('.p-name-input');
            nameInput.addEventListener('change', () => {
                p.name = nameInput.value;
                renderParticipantMarkers();
            });

            card.querySelector('.btn-del').addEventListener('click', () => {
                removeParticipant(p.id);
            });

            const chkCar = card.querySelector('.chk-has-car');
            const carOptions = card.querySelector('.car-options');
            chkCar.addEventListener('change', () => {
                p.has_car = chkCar.checked;
                carOptions.classList.toggle('hidden', !p.has_car);
                renderParticipantMarkers();
            });

            card.querySelector('.chk-share-ride').addEventListener('change', (event) => {
                p.share_ride = event.target.checked;
                renderParticipantMarkers();
            });

            container.appendChild(card);
        });
    }

    // Language Toggle Listeners
    document.getElementById('langEnBtn').addEventListener('click', () => setLanguage('en'));
    document.getElementById('langFrBtn').addEventListener('click', () => setLanguage('fr'));

    // Searchable Destination Combobox Event Handling
    const inputSearchDest = document.getElementById('inputSearchDestination');
    const destSearchResults = document.getElementById('destSearchResults');
    const btnClearDestSearch = document.getElementById('btnClearDestSearch');

    // Aggregate all destinations (places + stops)
    const bootstrapStops = bootstrap.stops || [];
    const bootstrapPlaces = bootstrap.places || [];

    const allDestinations = [
        { name: 'UMLP Montbéliard (CAMPUS)', lat: 47.49573, lon: 6.80400, type: 'university', badge: 'University' },
        ...bootstrapPlaces.map(p => ({ ...p, type: 'place', badge: p.category || 'Landmark' })),
        ...bootstrapStops.map(s => ({ ...s, type: 'stop', badge: 'Bus Stop' }))
    ];

    function renderDestSearchResults(filterText = '') {
        const query = filterText.trim().toLowerCase();
        let matches = [];

        if (!query) {
            matches = allDestinations.slice(0, 30);
        } else {
            matches = allDestinations.filter(d => d.name.toLowerCase().includes(query)).slice(0, 40);
        }

        if (matches.length === 0) {
            destSearchResults.innerHTML = `
                <div class="p-3 text-center text-gray-400">
                    No matching bus stops or landmarks found
                </div>
            `;
            destSearchResults.classList.remove('hidden');
            return;
        }

        destSearchResults.innerHTML = matches.map(m => `
            <div class="dest-item px-3.5 py-2.5 hover:bg-gray-50 cursor-pointer flex items-center justify-between gap-2 transition-colors" data-json="${encodeURIComponent(JSON.stringify(m))}">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-base shrink-0">${m.type === 'university' ? '🎓' : (m.type === 'place' ? '🏛️' : '🚏')}</span>
                    <span class="font-medium text-[#111827] truncate">${m.name}</span>
                </div>
                <span class="text-[10px] uppercase font-mono-tag px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 shrink-0">${m.badge}</span>
            </div>
        `).join('');

        destSearchResults.classList.remove('hidden');

        destSearchResults.querySelectorAll('.dest-item').forEach(item => {
            item.addEventListener('click', () => {
                const data = JSON.parse(decodeURIComponent(item.dataset.json));
                destination.name = data.name;
                destination.lat = data.lat;
                destination.lon = data.lon;
                inputSearchDest.value = data.name;
                btnClearDestSearch.classList.remove('hidden');
                destSearchResults.classList.add('hidden');
                updateDestinationMarker();
                map.flyTo([destination.lat, destination.lon], 14);
            });
        });
    }

    if (inputSearchDest) {
        inputSearchDest.addEventListener('focus', () => {
            renderDestSearchResults(inputSearchDest.value);
            if (inputSearchDest.value) btnClearDestSearch.classList.remove('hidden');
        });

        inputSearchDest.addEventListener('input', (e) => {
            btnClearDestSearch.classList.toggle('hidden', !e.target.value);
            renderDestSearchResults(e.target.value);
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#inputSearchDestination') && !e.target.closest('#destSearchResults')) {
                destSearchResults.classList.add('hidden');
            }
        });
    }

    if (btnClearDestSearch) {
        btnClearDestSearch.addEventListener('click', () => {
            inputSearchDest.value = '';
            btnClearDestSearch.classList.add('hidden');
            renderDestSearchResults('');
            inputSearchDest.focus();
        });
    }

    // Time Type Toggle (Depart at / Arrive by)
    const btnDepart = document.getElementById('btnTimeTypeDepart');
    const btnArrive = document.getElementById('btnTimeTypeArrive');

    btnDepart.addEventListener('click', () => {
        timeType = 'depart_at';
        btnDepart.className = 'text-xs font-medium py-1.5 rounded-md bg-white text-[#111827] shadow-sm transition-all';
        btnArrive.className = 'text-xs font-medium py-1.5 rounded-md text-[#4B5563] hover:text-[#111827] transition-all';
    });

    btnArrive.addEventListener('click', () => {
        timeType = 'arrive_by';
        btnArrive.className = 'text-xs font-medium py-1.5 rounded-md bg-white text-[#111827] shadow-sm transition-all';
        btnDepart.className = 'text-xs font-medium py-1.5 rounded-md text-[#4B5563] hover:text-[#111827] transition-all';
    });

    // Add Participant Button
    document.getElementById('btnAddParticipant').addEventListener('click', () => {
        addParticipant({
            name: `Participant ${participants.length + 1}`,
            lat: 47.6350 + (Math.random() - 0.5) * 0.05,
            lon: 6.8600 + (Math.random() - 0.5) * 0.05,
            has_car: false
        });
    });

    // Calculate Routes API Integration
    const btnCalculate = document.getElementById('btnCalculate');
    const calcIcon = document.getElementById('calcIcon');
    const calcSpinner = document.getElementById('calcSpinner');
    const calcText = document.getElementById('calcText');
    const errorBox = document.getElementById('errorBox');
    const errorMessage = document.getElementById('errorMessage');
    const planningLoader = document.getElementById('planningLoader');
    const planningProgressText = document.getElementById('planningProgressText');
    const planningProgressBar = document.getElementById('planningProgressBar');
    const planningParticipants = document.getElementById('planningParticipants');

    btnCalculate.addEventListener('click', async () => {
        errorBox.classList.add('hidden');

        if (participants.length === 0) {
            showError(t('val_no_participants'));
            return;
        }

        if (!destination.lat || !destination.lon) {
            showError(t('val_invalid_dest'));
            return;
        }

        const payload = {
            date: document.getElementById('inputDate').value,
            time: document.getElementById('inputTime').value,
            time_type: timeType,
            destination: {
                name: destination.name,
                lat: destination.lat,
                lon: destination.lon
            },
            participants: participants.map(p => ({
                name: p.name,
                lat: p.lat,
                lon: p.lon,
                has_car: p.has_car,
                share_ride: !p.has_car && p.share_ride
            }))
        };

        calcIcon.classList.add('hidden');
        calcSpinner.classList.remove('hidden');
        calcText.textContent = t('calc_running');
        btnCalculate.disabled = true;
        planningLoader.classList.remove('hidden');
        planningLoader.classList.add('flex');
        renderPlanningParticipants(participants.map(participant => ({ name: participant.name, status: 'pending' })));

        try {
            const res = await fetch('/api/plan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();

            if (!res.ok) {
                let errStr = data.message || 'Error occurred.';
                if (data.errors) {
                    const firstErr = Object.values(data.errors)[0];
                    if (firstErr && firstErr[0]) errStr = firstErr[0];
                }
                showError(errStr);
                return;
            }

            if (res.status !== 202 || !data.planning_id) {
                showError(data.message || 'Unable to start planning.');
                return;
            }

            const result = await waitForPlanning(data.planning_id);
            if (!result.options || result.options.length === 0) {
                showError(t('val_no_route'));
                return;
            }

            currentOptions = result.options;
            selectedOptionIndex = 0;
            displayResults();

        } catch (err) {
            console.error('API Error:', err);
            showError(err.message || t('network_err'));
        } finally {
            planningLoader.classList.add('hidden');
            planningLoader.classList.remove('flex');
            calcIcon.classList.remove('hidden');
            calcSpinner.classList.add('hidden');
            calcText.textContent = t('calc_btn');
            btnCalculate.disabled = false;
        }
    });

    function showError(msg) {
        errorMessage.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    // Left Sidebar Collapsible logic
    const leftSidebar = document.getElementById('leftSidebar');
    const btnToggleLeftSidebar = document.getElementById('btnToggleLeftSidebar');
    const leftChevronIcon = document.getElementById('leftChevronIcon');

    if (btnToggleLeftSidebar && leftSidebar) {
        btnToggleLeftSidebar.addEventListener('click', () => {
            const willCollapse = !leftSidebar.classList.contains('collapsed');
            leftSidebar.classList.toggle('collapsed');
            if (leftChevronIcon) {
                leftChevronIcon.style.transform = willCollapse ? 'rotate(180deg)' : 'rotate(0deg)';
            }
            setTimeout(() => {
                map.invalidateSize();
            }, 320);
        });
    }

    // Results Display & Right Sidebar Controls
    const rightSidebar = document.getElementById('rightSidebar');
    const optionTabs = document.getElementById('optionTabs');
    const resultsBody = document.getElementById('resultsBody');
    const btnCloseResults = document.getElementById('btnCloseResults');
    const btnOpenResultsFloating = document.getElementById('btnOpenResultsFloating');

    if (btnCloseResults && rightSidebar) {
        btnCloseResults.addEventListener('click', () => {
            rightSidebar.classList.add('hidden');
            if (currentOptions && currentOptions.length > 0 && btnOpenResultsFloating) {
                btnOpenResultsFloating.classList.remove('hidden');
            }
            setTimeout(() => {
                map.invalidateSize();
            }, 320);
        });
    }

    if (btnOpenResultsFloating && rightSidebar) {
        btnOpenResultsFloating.addEventListener('click', () => {
            rightSidebar.classList.remove('hidden');
            btnOpenResultsFloating.classList.add('hidden');
            setTimeout(() => {
                map.invalidateSize();
            }, 320);
        });
    }

    function displayResults() {
        if (!currentOptions || currentOptions.length === 0) return;
        if (rightSidebar) rightSidebar.classList.remove('hidden');
        if (btnOpenResultsFloating) btnOpenResultsFloating.classList.add('hidden');

        // On smaller screens, automatically collapse left sidebar so the map and options sidebar both fit cleanly
        if (window.innerWidth < 1024 && leftSidebar && !leftSidebar.classList.contains('collapsed')) {
            leftSidebar.classList.add('collapsed');
            if (leftChevronIcon) leftChevronIcon.style.transform = 'rotate(180deg)';
        }

        setTimeout(() => {
            map.invalidateSize();
        }, 150);

        optionTabs.innerHTML = '';
        currentOptions.forEach((opt, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            const isActive = idx === selectedOptionIndex;
            btn.className = `text-xs px-3 py-1 rounded-lg font-mono-tag font-semibold transition-all ${
                isActive ? 'bg-[#FF5722] text-white shadow-sm' : 'bg-white text-[#4B5563] hover:text-[#111827] border border-[#E5E7EB]'
            }`;
            btn.innerHTML = `${t('option')} ${idx + 1} (${opt.arrival_at_destination})`;
            btn.addEventListener('click', () => {
                selectedOptionIndex = idx;
                displayResults();
            });
            optionTabs.appendChild(btn);
        });

        const activeOption = currentOptions[selectedOptionIndex];

        const isCarpoolPickup = activeOption.strategy_type === 'car_pickup';
        const meetingName = activeOption.meeting_points && activeOption.meeting_points[0]
            ? activeOption.meeting_points[0].name
            : destination.name;
        const meetingPoint = activeOption.meeting_points && activeOption.meeting_points[0]
            ? activeOption.meeting_points[0]
            : null;
        let meetingLabel = meetingPoint ? meetingName : t('no_midpoint');
        if (isCarpoolPickup) {
            meetingLabel = t('carpool_pickup_strategy');
        }

        let carDecisionsHtml = '';
        if (activeOption.car_decisions && activeOption.car_decisions.length > 0) {
            carDecisionsHtml = activeOption.car_decisions.map(cd => {
                if (cd.pickup && cd.pickup.length > 0) {
                    const pickDetails = cd.pickup.map(pk =>
                        `<strong>${pk.who}</strong> ${t('carpool_picks_up')} ${t('carpool_at')} <em>${pk.at}</em> (${t('carpool_detour')} +${pk.detour_min} min, ${t('carpool_wait')} ${pk.wait_min} min)`
                    ).join(', ');
                    return `
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-900 font-serif-editorial">
                            🚗 <strong>${t('carpool_decision_title')}</strong> ${cd.driver} &mdash; ${pickDetails}.
                        </div>
                    `;
                }
                return '';
            }).join('');
        }

        const itinHtml = activeOption.itineraries.map(it => {
            const legsList = it.legs.map(leg => `
                <div class="flex items-center gap-3 text-xs py-1.5 border-b border-gray-100 last:border-b-0">
                    <span class="w-6 h-6 rounded-md flex items-center justify-center shrink-0 text-white font-bold text-[10px]" style="background-color: ${leg.line_color || '#6B7280'}">
                        ${getModeIcon(leg.mode)}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-[#111827] truncate">${leg.line || leg.mode}</span>
                            <span class="font-mono-tag text-[11px] text-[#4B5563]">${leg.depart} &rarr; ${leg.arrive}</span>
                        </div>
                        <div class="text-[11px] text-[#4B5563] truncate">
                            ${leg.from.name} &rarr; ${leg.to.name}
                        </div>
                    </div>
                    <span class="font-mono-tag text-[10px] text-gray-500 whitespace-nowrap">${leg.duration_min} min</span>
                </div>
            `).join('');
            const hasMultipleStops = it.legs.filter(leg => ['bus', 'train', 'bike', 'shared_car'].includes(leg.mode)).length > 0;
            const legsContent = hasMultipleStops ? `
                <details class="group">
                    <summary class="cursor-pointer list-none flex items-center justify-between text-xs font-semibold text-[#111827]">
                        <span>${t('show_route_details')}</span>
                        <span class="text-[#4B5563] transition-transform group-open:rotate-180">&#9662;</span>
                    </summary>
                    <div class="mt-2 space-y-1">${legsList}</div>
                </details>
            ` : legsList;

            return `
                <div class="border border-[#E5E7EB] rounded-xl p-3.5 bg-white space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="font-display font-bold text-sm text-[#111827] flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full" style="background-color: ${getParticipantColor(it.participant)};"></span>
                            ${it.participant}
                        </span>
                        <div class="flex items-center gap-2 text-[11px] font-mono-tag text-[#4B5563]">
                            <span class="bg-gray-100 px-2 py-0.5 rounded">${t('duration')} ${it.total_duration_min} min</span>
                            <span class="bg-gray-100 px-2 py-0.5 rounded">${t('wait')} ${it.wait_min || 0} min</span>
                            <span class="bg-gray-100 px-2 py-0.5 rounded">${t('walk')} ${it.walking_m || 0} m</span>
                        </div>
                    </div>

                    <div class="bg-[#F9FAFB] rounded-lg p-2.5 border border-gray-100 space-y-1">
                        ${legsContent}
                    </div>
                </div>
            `;
        }).join('');

        resultsBody.innerHTML = `
            <div class="flex flex-wrap items-center justify-between gap-3 bg-[#FDFDFD] border border-[#E5E7EB] rounded-xl p-3.5">
                <div>
                    <span class="text-[11px] uppercase tracking-wider text-[#4B5563] font-mono-tag">${t('meeting_place')}</span>
                    <h3 class="font-display font-bold text-base text-[#111827]">${meetingLabel}</h3>
                    ${meetingPoint ? `
                        <div class="mt-1 text-[11px] text-[#4B5563] font-mono-tag">
                            ${t('midpoint_coordinates')} ${Number(meetingPoint.lat).toFixed(5)}, ${Number(meetingPoint.lon).toFixed(5)}
                        </div>
                    ` : ''}
                </div>
                <div class="text-right">
                    <span class="text-[11px] uppercase tracking-wider text-[#4B5563] font-mono-tag">${t('group_arrival')}</span>
                    <div class="font-mono-tag font-bold text-lg text-[#FF5722]">${activeOption.arrival_at_destination}</div>
                </div>
            </div>

            ${carDecisionsHtml}

            <div class="space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-[#4B5563] font-mono-tag">${t('individual_itineraries')}</h4>
                <div class="space-y-3">
                    ${itinHtml}
                </div>
            </div>
        `;

        renderOptionRoutes(activeOption);
    }

    function renderOptionRoutes(option) {
        routesLayer.clearLayers();
        hubsLayer.clearLayers();

        const allLatLngs = [];

        if (option.meeting_points && option.meeting_points.length > 0) {
            option.meeting_points.forEach(mp => {
                const isPickup = mp.type === 'pickup';
                const icon = isPickup ? createPickupIcon() : createMeetingPointIcon();
                const label = isPickup ? `${t('pickup_point')} ${mp.name}` : `${t('convergence_point')} ${mp.name}`;

                const hubMarker = L.marker([mp.lat, mp.lon], {
                    icon: icon
                }).addTo(hubsLayer);

                hubMarker.bindTooltip(label, {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -12],
                    className: 'meeting-point-tooltip'
                });
                hubMarker.bindPopup(`
                    <strong>${isPickup ? t('pickup_point') : t('convergence_point')}</strong><br>
                    ${mp.name}<br>
                    <small>${Number(mp.lat).toFixed(5)}, ${Number(mp.lon).toFixed(5)}</small>
                `);
                allLatLngs.push([mp.lat, mp.lon]);
            });
        }

        option.itineraries.forEach(it => {
            it.legs.forEach(leg => {
                const geom = leg.geometry || [
                    [leg.from.lat, leg.from.lon],
                    [leg.to.lat, leg.to.lon]
                ];

                const participantColor = getParticipantColor(it.participant);
                const color = participantColor;
                const isDashed = leg.mode === 'walk' || leg.mode === 'car_passenger';

                const poly = L.polyline(geom, {
                    color: color,
                    weight: leg.mode === 'walk' ? 3 : 5,
                    dashArray: isDashed ? '6, 8' : null,
                    opacity: 0.85
                }).addTo(routesLayer);

                poly.bindPopup(`
                    <strong style="color: ${participantColor}">${it.participant}</strong> &mdash; ${leg.mode.toUpperCase()}<br>
                    <small>${t('route_color')} ${participantColor}</small><br>
                    <strong>${leg.line ? leg.line : t('direct')}</strong><br>
                    <strong>${leg.depart} &rarr; ${leg.arrive}</strong> (${leg.duration_min} min)<br>
                    <small>${leg.from.name} &rarr; ${leg.to.name}</small>
                `);

                geom.forEach(pt => allLatLngs.push(pt));
            });
        });

        if (allLatLngs.length > 1) {
            map.fitBounds(allLatLngs, { padding: [50, 50], maxZoom: 14 });
        }
    }

    function getParticipantColor(name) {
        const found = participants.find(p => p.name === name);
        return found ? found.color : '#3B82F6';
    }

    function getModeIcon(mode) {
        switch (mode) {
            case 'car': return '🚗';
            case 'car_passenger': return '👥';
            case 'train': return '🚆';
            case 'bus': return '🚌';
            case 'bike': return '🚲';
            case 'walk': return '🚶';
            case 'shared_car': return '🚙';
            default: return '📍';
        }
    }

    function getModeColor(mode) {
        switch (mode) {
            case 'car': return '#FF5722';
            case 'car_passenger': return '#FB923C';
            case 'train': return '#7C3AED';
            case 'bus': return '#2563EB';
            case 'bike': return '#059669';
            case 'walk': return '#9CA3AF';
            default: return '#6B7280';
        }
    }

    // Initialize Default State & Apply Language
    applyLanguage();
    updateDestinationMarker();
});
