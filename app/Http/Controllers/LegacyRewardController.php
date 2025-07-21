<?php

namespace App\Http\Controllers;

use App\Models\LegacyReward;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * @OA\Tag(
 *     name="LegacyRewards",
 *     description="API Endpoints for managing user legacy rewards"
 * )
 */
class LegacyRewardController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/legacy-rewards/{user_id}",
     *     summary="Get user's legacy rewards history",
     *     description="Get history of completed legacy rewards for a user. A legacy reward is created when all three legs reach $45,000.",
     *     operationId="getUserLegacyRewards",
     *     tags={"LegacyRewards"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="Unique user ID (e.g. MC34234)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=false,
     *         description="Start date for filtering (Y-m-d)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=false,
     *         description="End date for filtering (Y-m-d)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Legacy rewards history retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="registration_date", type="string", format="date", example="2024-01-01")
     *             ),
     *             @OA\Property(property="legacy_rewards", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="date", type="string", format="date", example="2024-02-19"),
     *                     @OA\Property(property="leg_a_balance", type="number", format="float", example=45000.00, description="Total balance of leg A when reward was earned"),
     *                     @OA\Property(property="leg_b_balance", type="number", format="float", example=45000.00, description="Total balance of leg B when reward was earned"),
     *                     @OA\Property(property="leg_c_balance", type="number", format="float", example=45000.00, description="Total balance of leg C when reward was earned"),
     *                     @OA\Property(property="reward_amount", type="number", format="float", example=15000.00, description="Reward amount received"),
     *                     @OA\Property(property="is_rewarded", type="boolean", example=true, description="Whether the reward was paid")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     )
     * )
     */
    public function getUserLegacyRewards(Request $request, $userId)
    {
        $user = User::where('user_id', $userId)->firstOrFail();

        $query = LegacyReward::where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        } else {
            $query->whereDate('created_at', '>=', $user->created_at);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $legacyRewards = $query->get()->map(function ($reward) {
            return [
                'id' => $reward->id,
                'date' => $reward->created_at->format('Y-m-d'),
                'leg_a_balance' => $reward->leg_a_balance,
                'leg_b_balance' => $reward->leg_b_balance,
                'leg_c_balance' => $reward->leg_c_balance,
                'reward_amount' => $reward->reward_amount,
                'is_rewarded' => $reward->is_rewarded,
            ];
        });

        return response()->json([
            'user' => [
                'user_id' => $user->user_id,
                'registration_date' => $user->created_at->format('Y-m-d')
            ],
            'legacy_rewards' => $legacyRewards
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/legacy-rewards/{user_id}/summary",
     *     summary="Get user's legacy rewards summary",
     *     description="Get summary of user's legacy rewards including total rewards and current leg balances",
     *     operationId="getUserLegacyRewardsSummary",
     *     tags={"LegacyRewards"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="Unique user ID (e.g. MC34234)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Legacy rewards summary retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="total_legacy_rewards", type="number", format="float", example=30000.00, description="Total legacy rewards received"),
     *             @OA\Property(property="current_leg_balances", type="object",
     *                 @OA\Property(property="leg_a", type="number", format="float", example=45000.00, description="Current balance of leg A"),
     *                 @OA\Property(property="leg_b", type="number", format="float", example=45000.00, description="Current balance of leg B"),
     *                 @OA\Property(property="leg_c", type="number", format="float", example=45000.00, description="Current balance of leg C")
     *             ),
     *             @OA\Property(property="last_legacy_reward_date", type="string", format="date", example="2024-02-19", description="Date of last legacy reward received")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     )
     * )
     */
    public function getUserLegacyRewardsSummary($userId)
    {
        $user = User::where('user_id', $userId)->firstOrFail();

        $latestReward = LegacyReward::where('user_id', $userId)
            ->latest()
            ->first();

        $totalRewards = LegacyReward::where('user_id', $userId)
            ->where('is_rewarded', true)
            ->sum('reward_amount');

        $lastReward = LegacyReward::where('user_id', $userId)
            ->where('is_rewarded', true)
            ->latest()
            ->first();

        return response()->json([
            'total_legacy_rewards' => $totalRewards,
            'current_leg_balances' => [
                'leg_a' => $latestReward ? $latestReward->leg_a_balance : 0,
                'leg_b' => $latestReward ? $latestReward->leg_b_balance : 0,
                'leg_c' => $latestReward ? $latestReward->leg_c_balance : 0
            ],
            'last_legacy_reward_date' => $lastReward ? $lastReward->created_at->format('Y-m-d') : null
        ]);
    }
} 