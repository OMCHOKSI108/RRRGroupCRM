@extends('layouts.app')

@section('title', __('Admin Client Cluster Map'))
@section('page-title', __('Client Cluster Map'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('reports.index') }}">{{ __('Insights') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('Client Cluster Map') }}</li>
@endsection

@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<style>
    #map-container {
        position: relative;
        height: 640px;
        width: 100%;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid var(--tblr-border-color, #e6e8e9);
    }
    #cluster-map {
        height: 100%;
        width: 100%;
        z-index: 1;
    }
    .map-spinner-overlay {
        position: absolute;
        top: 15px;
        right: 15px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.9);
        padding: 6px 14px;
        border-radius: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        display: none;
        align-items: center;
        gap: 8px;
        font-size: 0.825rem;
        font-weight: 500;
    }
    .cluster-marker-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #ffffff;
        font-weight: 700;
        text-align: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        border: 2px solid #ffffff;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .cluster-marker-icon:hover {
        transform: scale(1.12);
        box-shadow: 0 6px 16px rgba(0,0,0,0.35);
        z-index: 1000 !important;
    }
    .cluster-sm {
        background: linear-gradient(135deg, #206bc4, #4299e1);
        font-size: 11px;
    }
    .cluster-md {
        background: linear-gradient(135deg, #0ca678, #2fb344);
        font-size: 13px;
    }
    .cluster-lg {
        background: linear-gradient(135deg, #f59f00, #ff922b);
        font-size: 14px;
    }
    .cluster-xl {
        background: linear-gradient(135deg, #d63939, #f03e3e);
        font-size: 15px;
    }
    .client-single-marker {
        background: linear-gradient(135deg, #7952b3, #ae3ec9);
        border: 2px solid #ffffff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        cursor: pointer;
    }
    .map-legend {
        background: rgba(255, 255, 255, 0.95);
        padding: 10px 14px;
        border-radius: 6px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        font-size: 0.75rem;
        line-height: 1.5;
    }
    .map-legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
    }
    .map-legend-color {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 1px solid #fff;
    }
</style>
@endpush

@section('content')
<div class="container-xl">

    {{-- Alert for Missing Coordinates if any --}}
    <div id="missing-coords-alert" class="alert alert-info alert-dismissible mb-3 d-none" role="alert">
        <div class="d-flex">
            <div>
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12.01" y2="8" /><polyline points="11 12 12 12 12 16 13 16" /></svg>
            </div>
            <div>
                <h4 class="alert-title">{{ __('Location Data Notice') }}</h4>
                <div class="text-secondary">
                    <span id="missing-coords-text"></span>
                </div>
            </div>
        </div>
        <a class="btn-close" data-bs-dismiss="alert" aria-label="close"></a>
    </div>

    {{-- Error Alert --}}
    <div id="map-error-alert" class="alert alert-danger alert-dismissible mb-3 d-none" role="alert">
        <div class="d-flex">
            <div>
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
            </div>
            <div>
                <h4 class="alert-title">{{ __('Error Loading Map Data') }}</h4>
                <div id="map-error-text" class="text-secondary"></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="fetchClusters()">{{ __('Retry') }}</button>
    </div>

    {{-- Summary KPI Cards --}}
    <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <span class="bg-primary text-white avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/></svg>
                            </span>
                        </div>
                        <div class="col">
                            <div class="font-weight-medium" id="kpi-total-clients">-</div>
                            <div class="text-secondary">{{ __('Total Matching Clients') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <span class="bg-success text-white avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="11" r="3"/><path d="M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0z"/></svg>
                            </span>
                        </div>
                        <div class="col">
                            <div class="font-weight-medium text-success" id="kpi-with-coords">-</div>
                            <div class="text-secondary">{{ __('Clients on Map') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <span class="bg-warning text-white avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </span>
                        </div>
                        <div class="col">
                            <div class="font-weight-medium text-warning" id="kpi-without-coords">-</div>
                            <div class="text-secondary">{{ __('Missing Coordinates') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <span class="bg-info text-white avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M20 12h2"/><path d="M2 12h2"/></svg>
                            </span>
                        </div>
                        <div class="col">
                            <div class="font-weight-medium" id="kpi-viewport-clusters">-</div>
                            <div class="text-secondary">{{ __('Clusters in Viewport') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters Card --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form id="filter-form" class="row g-2 align-items-end" onsubmit="event.preventDefault(); fetchClusters();">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">{{ __('Created From') }}</label>
                    <input type="date" id="filter-from" class="form-control form-control-sm" />
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">{{ __('Created To') }}</label>
                    <input type="date" id="filter-to" class="form-control form-control-sm" />
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">{{ __('Assigned Agent') }}</label>
                    <select id="filter-assigned" class="form-select form-select-sm">
                        <option value="">{{ __('All Agents') }}</option>
                        @foreach($assignedUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">{{ __('Client Status') }}</label>
                    <select id="filter-status" class="form-select form-select-sm">
                        <option value="">{{ __('All Statuses') }}</option>
                        @foreach($leadStatuses as $status)
                            <option value="{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 col-sm-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.828 4.828a2 2 0 0 0 -.586 1.414v3.172l-4 4v-7.172a2 2 0 0 0 -.586 -1.414l-4.828 -4.828a2 2 0 0 1 -.586 -1.414v-2.172z"/></svg>
                        {{ __('Apply Filters') }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                        {{ __('Reset') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Map Card --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">{{ __('Geographic Concentration') }}</h3>
            <div class="text-secondary small">
                {{ __('Zoom in to inspect regional and localized client clusters') }}
            </div>
        </div>
        <div class="card-body p-0">
            <div id="map-container">
                <div id="cluster-map"></div>
                <div id="map-spinner" class="map-spinner-overlay">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span>{{ __('Updating clusters...') }}</span>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    let map = null;
    let markersLayer = null;
    let abortController = null;
    let debounceTimer = null;
    let isFetching = false;

    document.addEventListener('DOMContentLoaded', function () {
        initMap();
    });

    function initMap() {
        // Initial center on Continental US (lat: 38.5, lng: -96.5, zoom: 4)
        map = L.map('cluster-map', {
            center: [38.5, -96.5],
            zoom: 4,
            minZoom: 2,
            maxZoom: 18,
            worldCopyJump: true
        });

        // OpenStreetMap tile layer with attribution
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
        }).addTo(map);

        markersLayer = L.layerGroup().addTo(map);

        // Add Legend control
        addLegend();

        // Listen for map movements and debounce cluster fetch
        map.on('moveend', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchClusters, 300);
        });

        // Initial fetch
        fetchClusters();
    }

    function addLegend() {
        const legend = L.control({ position: 'bottomleft' });
        legend.onAdd = function () {
            const div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML = `
                <div class="fw-bold mb-2">{{ __('Client Clusters') }}</div>
                <div class="map-legend-item">
                    <span class="map-legend-color" style="background: #206bc4;"></span>
                    <span>1 - 9 clients</span>
                </div>
                <div class="map-legend-item">
                    <span class="map-legend-color" style="background: #0ca678;"></span>
                    <span>10 - 49 clients</span>
                </div>
                <div class="map-legend-item">
                    <span class="map-legend-color" style="background: #f59f00;"></span>
                    <span>50 - 99 clients</span>
                </div>
                <div class="map-legend-item">
                    <span class="map-legend-color" style="background: #d63939;"></span>
                    <span>100+ clients</span>
                </div>
                <div class="map-legend-item mt-1">
                    <span class="map-legend-color" style="background: #7952b3;"></span>
                    <span>{{ __('Single Client') }}</span>
                </div>
            `;
            return div;
        };
        legend.addTo(map);
    }

    function getClusterClass(count) {
        if (count >= 100) return { className: 'cluster-xl', size: 54 };
        if (count >= 50) return { className: 'cluster-lg', size: 46 };
        if (count >= 10) return { className: 'cluster-md', size: 38 };
        return { className: 'cluster-sm', size: 30 };
    }

    function fetchClusters() {
        if (!map) return;

        // Cancel previous pending request
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        const spinner = document.getElementById('map-spinner');
        spinner.style.display = 'flex';

        const errorAlert = document.getElementById('map-error-alert');
        errorAlert.classList.add('d-none');

        const zoom = map.getZoom();
        const bounds = map.getBounds();
        
        // Wrap coordinates to [-180, 180] and [-90, 90]
        let west = Math.max(-180, Math.min(180, bounds.getWest()));
        let south = Math.max(-90, Math.min(90, bounds.getSouth()));
        let east = Math.max(-180, Math.min(180, bounds.getEast()));
        let north = Math.max(-90, Math.min(90, bounds.getNorth()));

        // Format bbox: west,south,east,north
        const bboxStr = `${west.toFixed(4)},${south.toFixed(4)},${east.toFixed(4)},${north.toFixed(4)}`;

        const params = new URLSearchParams({
            bbox: bboxStr,
            zoom: zoom,
            limit: 500
        });

        const fromVal = document.getElementById('filter-from').value;
        const toVal = document.getElementById('filter-to').value;
        const assignedVal = document.getElementById('filter-assigned').value;
        const statusVal = document.getElementById('filter-status').value;

        if (fromVal) params.append('from', fromVal);
        if (toVal) params.append('to', toVal);
        if (assignedVal) params.append('assignedUserId', assignedVal);
        if (statusVal) params.append('status', statusVal);

        fetch(`/api/v1/admin/analytics/client-map/clusters?${params.toString()}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: abortController.signal
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.message || err.error || 'Server returned status ' + response.status);
                });
            }
            return response.json();
        })
        .then(res => {
            spinner.style.display = 'none';
            renderClusters(res.data);
        })
        .catch(err => {
            if (err.name === 'AbortError') {
                return; // Normal cancellation on fast panning
            }
            spinner.style.display = 'none';
            console.error('Failed to load clusters:', err);
            document.getElementById('map-error-text').innerText = err.message || 'An error occurred while fetching client clusters.';
            errorAlert.classList.remove('d-none');
        });
    }

    function renderClusters(data) {
        if (!data || !markersLayer) return;

        markersLayer.clearLayers();

        const summary = data.summary || {};
        const clusters = data.clusters || [];

        // Update KPI metrics
        document.getElementById('kpi-total-clients').innerText = Number(summary.totalMatchingClients || 0).toLocaleString();
        document.getElementById('kpi-with-coords').innerText = Number(summary.clientsWithCoordinates || 0).toLocaleString();
        document.getElementById('kpi-without-coords').innerText = Number(summary.clientsWithoutCoordinates || 0).toLocaleString();
        document.getElementById('kpi-viewport-clusters').innerText = clusters.length.toLocaleString();

        // Missing coordinates notice
        const missingNotice = document.getElementById('missing-coords-alert');
        const missingText = document.getElementById('missing-coords-text');
        if (summary.clientsWithoutCoordinates > 0) {
            missingText.innerText = `${summary.clientsWithoutCoordinates} matching client(s) lack latitude/longitude coordinates and cannot be displayed on the map.`;
            missingNotice.classList.remove('d-none');
        } else {
            missingNotice.classList.add('d-none');
        }

        clusters.forEach(cluster => {
            const count = cluster.clientCount;
            const lat = cluster.latitude;
            const lng = cluster.longitude;

            // Single client detailed marker at high zoom
            if (cluster.client) {
                const singleIcon = L.divIcon({
                    className: 'custom-single-marker-wrapper',
                    html: `<div class="client-single-marker" style="width: 24px; height: 24px;" title="${cluster.client.name}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M12 2a5 5 0 0 1 5 5v3a5 5 0 0 1-10 0V7a5 5 0 0 1 5-5z"/><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/></svg>
                          </div>`,
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                });

                const marker = L.marker([lat, lng], { icon: singleIcon });
                const leadUrl = `/leads/${cluster.client.id}`;
                marker.bindPopup(`
                    <div style="min-width: 180px;">
                        <h4 class="mb-1 fw-bold">${cluster.client.name}</h4>
                        <div class="mb-2">
                            <span class="badge bg-blue-lt text-uppercase">${cluster.client.status}</span>
                        </div>
                        <div class="text-secondary small mb-2">
                            ${cluster.client.city ? cluster.client.city + ', ' : ''}${cluster.client.state || ''}
                        </div>
                        <a href="${leadUrl}" class="btn btn-sm btn-primary w-100" target="_blank">{{ __('View Client') }}</a>
                    </div>
                `);
                markersLayer.addLayer(marker);
                return;
            }

            // Cluster marker
            const style = getClusterClass(count);
            const icon = L.divIcon({
                className: 'custom-cluster-wrapper',
                html: `<div class="cluster-marker-icon ${style.className}" style="width: ${style.size}px; height: ${style.size}px;">${count}</div>`,
                iconSize: [style.size, style.size],
                iconAnchor: [style.size / 2, style.size / 2]
            });

            const marker = L.marker([lat, lng], { icon: icon });

            marker.on('click', function () {
                if (cluster.bounds) {
                    const b = cluster.bounds;
                    // Zoom into cluster bounds
                    map.fitBounds([
                        [b.south, b.west],
                        [b.north, b.east]
                    ], { padding: [50, 50], maxZoom: 16 });
                } else {
                    map.setView([lat, lng], map.getZoom() + 2);
                }
            });

            marker.bindTooltip(`<strong>${count.toLocaleString()} clients</strong><br><small class="text-muted">Click to zoom in</small>`, {
                direction: 'top',
                offset: [0, -style.size / 2]
            });

            markersLayer.addLayer(marker);
        });
    }

    function resetFilters() {
        document.getElementById('filter-from').value = '';
        document.getElementById('filter-to').value = '';
        document.getElementById('filter-assigned').value = '';
        document.getElementById('filter-status').value = '';
        fetchClusters();
    }
</script>
@endpush
