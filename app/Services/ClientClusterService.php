<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientClusterService
{
    /**
     * Zoom level to degree grid step mapping.
     */
    protected array $gridSteps = [
        0 => 30.0,
        1 => 20.0,
        2 => 12.0,
        3 => 8.0,
        4 => 4.0,
        5 => 2.0,
        6 => 1.0,
        7 => 0.5,
        8 => 0.25,
        9 => 0.12,
        10 => 0.06,
        11 => 0.03,
        12 => 0.015,
        13 => 0.008,
        14 => 0.004,
        15 => 0.002,
        16 => 0.001,
        17 => 0.0005,
        18 => 0.00025,
    ];

    /**
     * Get clusters and summary for a given tenant and query filters.
     *
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getClusters(int $tenantId, array $filters = []): array
    {
        $zoom = isset($filters['zoom']) ? (int) $filters['zoom'] : 3;
        $zoom = max(0, min(22, $zoom));

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 500;
        $limit = max(1, min(2000, $limit));

        $bbox = $filters['bbox'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $assignedUserId = $filters['assignedUserId'] ?? null;
        $status = $filters['status'] ?? null;

        // Base query for matching clients within tenant
        $baseQuery = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');

        $filtersApplied = [];

        if (!empty($from)) {
            $baseQuery->where('created_at', '>=', Carbon::parse($from)->startOfDay());
            $filtersApplied['from'] = $from;
        }

        if (!empty($to)) {
            $baseQuery->where('created_at', '<=', Carbon::parse($to)->endOfDay());
            $filtersApplied['to'] = $to;
        }

        if (!empty($assignedUserId)) {
            $baseQuery->where('agent_id', (int) $assignedUserId);
            $filtersApplied['assignedUserId'] = (int) $assignedUserId;
        }

        if (!empty($status)) {
            $baseQuery->where('status', $status);
            $filtersApplied['status'] = $status;
        }

        // Summary counts (tenant-scoped and filter-scoped)
        $totalMatchingClients = (clone $baseQuery)->count();
        $clientsWithCoordinates = (clone $baseQuery)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->count();
        $clientsWithoutCoordinates = $totalMatchingClients - $clientsWithCoordinates;

        if ($bbox) {
            $filtersApplied['bbox'] = $bbox;
        }

        // If no clients with coordinates, return early with valid empty response
        if ($clientsWithCoordinates === 0) {
            return [
                'data' => [
                    'clusters' => [],
                    'summary' => [
                        'totalMatchingClients' => $totalMatchingClients,
                        'clientsWithCoordinates' => 0,
                        'clientsWithoutCoordinates' => $clientsWithoutCoordinates,
                        'returnedClusterCount' => 0,
                    ],
                    'meta' => [
                        'zoom' => $zoom,
                        'filtersApplied' => $filtersApplied,
                        'hasMore' => false,
                    ],
                ],
            ];
        }

        // Build cluster query
        $clusterQuery = (clone $baseQuery)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Apply viewport bounding box if provided
        if ($bbox) {
            $west = (float) $bbox['west'];
            $south = (float) $bbox['south'];
            $east = (float) $bbox['east'];
            $north = (float) $bbox['north'];

            $clusterQuery->whereBetween('latitude', [$south, $north]);

            if ($west <= $east) {
                // Standard bounding box
                $clusterQuery->whereBetween('longitude', [$west, $east]);
            } else {
                // Antimeridian crossing: longitude >= west OR longitude <= east
                $clusterQuery->where(function ($q) use ($west, $east) {
                    $q->where('longitude', '>=', $west)
                      ->orWhere('longitude', '<=', $east);
                });
            }
        }

        $step = $this->gridSteps[$zoom] ?? max(0.0001, 30.0 / (2 ** $zoom));
        $stepStr = number_format($step, 6, '.', '');

        $results = $clusterQuery
            ->selectRaw("
                FLOOR(latitude / {$stepStr}) * {$stepStr} AS cell_lat,
                FLOOR(longitude / {$stepStr}) * {$stepStr} AS cell_lng,
                COUNT(id) AS client_count,
                AVG(latitude) AS avg_lat,
                AVG(longitude) AS avg_lng,
                MIN(id) AS sample_lead_id
            ")
            ->groupBy('cell_lat', 'cell_lng')
            ->orderByDesc('client_count')
            ->limit($limit + 1)
            ->get();

        $hasMore = $results->count() > $limit;
        if ($hasMore) {
            $results = $results->slice(0, $limit);
        }

        // For high zoom levels with single-client clusters, load client preview data safely
        $singleLeadIds = [];
        if ($zoom >= 13) {
            foreach ($results as $row) {
                if ((int) $row->client_count === 1 && $row->sample_lead_id) {
                    $singleLeadIds[] = (int) $row->sample_lead_id;
                }
            }
        }

        $leadsById = [];
        if (!empty($singleLeadIds)) {
            $leads = Lead::withoutGlobalScopes()
                ->whereIn('id', $singleLeadIds)
                ->where('tenant_id', $tenantId)
                ->get();

            $properties = Property::withoutGlobalScopes()
                ->whereIn('lead_id', $singleLeadIds)
                ->where('tenant_id', $tenantId)
                ->get()
                ->keyBy('lead_id');

            foreach ($leads as $lead) {
                $prop = $properties->get($lead->id);
                $leadsById[$lead->id] = [
                    'id' => $lead->id,
                    'name' => $lead->full_name,
                    'status' => $lead->status,
                    'city' => $prop?->city ?? '',
                    'state' => $prop?->state ?? '',
                ];
            }
        }

        $clusters = [];
        foreach ($results as $row) {
            $cellLat = (float) $row->cell_lat;
            $cellLng = (float) $row->cell_lng;
            $clientCount = (int) $row->client_count;

            $cluster = [
                'id' => 'cluster_' . substr(md5("{$zoom}_{$cellLat}_{$cellLng}"), 0, 12),
                'latitude' => round((float) $row->avg_lat, 6),
                'longitude' => round((float) $row->avg_lng, 6),
                'clientCount' => $clientCount,
                'bounds' => [
                    'south' => round($cellLat, 6),
                    'west' => round($cellLng, 6),
                    'north' => round($cellLat + $step, 6),
                    'east' => round($cellLng + $step, 6),
                ],
            ];

            if ($zoom >= 13 && $clientCount === 1 && isset($leadsById[$row->sample_lead_id])) {
                $cluster['client'] = $leadsById[$row->sample_lead_id];
            }

            $clusters[] = $cluster;
        }

        return [
            'data' => [
                'clusters' => $clusters,
                'summary' => [
                    'totalMatchingClients' => $totalMatchingClients,
                    'clientsWithCoordinates' => $clientsWithCoordinates,
                    'clientsWithoutCoordinates' => $clientsWithoutCoordinates,
                    'returnedClusterCount' => count($clusters),
                ],
                'meta' => [
                    'zoom' => $zoom,
                    'filtersApplied' => $filtersApplied,
                    'hasMore' => $hasMore,
                ],
            ],
        ];
    }
}
