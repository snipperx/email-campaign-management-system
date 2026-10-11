<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Resources\CampaignDetailResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampaignController extends Controller
{
    /**
     * Display a listing of campaigns, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        $campaigns = Campaign::query()
            ->orderByDesc('created_at')
            ->get();

        return CampaignResource::collection($campaigns);
    }

    /**
     * Store a newly created campaign and dispatch asynchronous sending jobs.
     */
    public function store(StoreCampaignRequest $request, CreateCampaignAction $action): JsonResponse
    {
        $campaign = $action->execute($request->validated());

        return response()->json([
            'campaign_id' => $campaign->id,
            'recipient_count' => $campaign->recipient_count,
            'status' => $campaign->status->value,
        ], 201);
    }

    /**
     * Display the specified campaign and its recipient statuses.
     */
    public function show(int $id): CampaignDetailResource|JsonResponse
    {
        $campaign = Campaign::with('emailJobs')->find($id);

        if (! $campaign) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        return new CampaignDetailResource($campaign);
    }
}
