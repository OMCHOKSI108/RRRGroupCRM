<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientClusterRequest;
use App\Models\User;
use App\Services\ClientClusterService;
use App\Services\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientMapController extends Controller
{
    public function __construct(
        protected ClientClusterService $clusterService
    ) {}

    /**
     * Render the Admin Client Cluster Map page.
     */
    public function index(Request $request): View
    {
        $tenant = auth()->user()->tenant;

        $assignedUsers = User::assignable($tenant)
            ->orderBy('name')
            ->get(['id', 'name']);

        $leadStatuses = CustomFieldService::getValidSlugs('lead_status');

        return view('admin.client-map.index', [
            'tenant' => $tenant,
            'assignedUsers' => $assignedUsers,
            'leadStatuses' => $leadStatuses,
        ]);
    }

    /**
     * API endpoint returning geographic client clusters and summary metrics.
     *
     * GET /api/v1/admin/analytics/client-map/clusters
     */
    public function clusters(ClientClusterRequest $request): JsonResponse
    {
        $tenant = auth()->user()?->tenant ?? $request->attributes->get('tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        // Enforce admin role when authenticated via user session
        if (auth()->check() && !auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized action.'], 403);
        }

        $filters = [
            'bbox' => $request->getParsedBbox(),
            'zoom' => $request->query('zoom') !== null ? (int) $request->query('zoom') : 3,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'assignedUserId' => $request->query('assignedUserId'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit') !== null ? (int) $request->query('limit') : 500,
        ];

        $data = $this->clusterService->getClusters($tenant->id, $filters);

        return response()->json($data);
    }
}
