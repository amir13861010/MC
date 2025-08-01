<?php

namespace App\Http\Controllers;

use App\Models\Reward;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * @OA\Tag(
 *     name="Rewards",
 *     description="API Endpoints for managing user rewards"
 * )
 */
class RewardController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/rewards/{user_id}",
     *     summary="Get user's rewards history",
     *     description="Get history of completed leg rewards for a user. A reward is created when all three legs reach $1500.",
     *     operationId="getUserRewards",
     *     tags={"Rewards"},
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
     *         description="Rewards history retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="registration_date", type="string", format="date", example="2024-01-01")
     *             ),
     *             @OA\Property(property="rewards", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="date", type="string", format="date", example="2024-02-19"),
     *                     @OA\Property(property="leg_a_balance", type="number", format="float", example=1500.00, description="Total balance of leg A when reward was earned"),
     *                     @OA\Property(property="leg_b_balance", type="number", format="float", example=1500.00, description="Total balance of leg B when reward was earned"),
     *                     @OA\Property(property="leg_c_balance", type="number", format="float", example=1500.00, description="Total balance of leg C when reward was earned"),
     *                     @OA\Property(property="reward_amount", type="number", format="float", example=500.00, description="Reward amount received"),
     *                     @OA\Property(property="is_rewarded", type="boolean", example=true, description="Whether the reward was paid"),
     *                     @OA\Property(property="completed_at", type="string", format="date-time", example="2024-02-19T00:00:00Z", description="When the reward was completed")
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
    public function getUserRewards(Request $request, $userId)
    {
        // Verify user exists
        $user = User::where('user_id', $userId)->firstOrFail();

        // Build query
        $query = Reward::where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // Apply date filters if provided
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        } else {
            // If no start date provided, use user's registration date
            $query->whereDate('created_at', '>=', $user->created_at);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Get rewards
        $rewards = $query->get()->map(function ($reward) {
            return [
                'id' => $reward->id,
                'date' => $reward->created_at->format('Y-m-d'),
                'leg_a_balance' => $reward->leg_a_balance,
                'leg_b_balance' => $reward->leg_b_balance,
                'leg_c_balance' => $reward->leg_c_balance,
                'reward_amount' => $reward->reward_amount,
                'is_rewarded' => $reward->is_rewarded,
                'completed_at' => $reward->completed_at ? $reward->completed_at->format('Y-m-d\TH:i:s\Z') : null
            ];
        });

        return response()->json([
            'user' => [
                'user_id' => $user->user_id,
                'registration_date' => $user->created_at->format('Y-m-d')
            ],
            'rewards' => $rewards
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/rewards/{user_id}/summary",
     *     summary="Get user's rewards summary",
     *     description="Get summary of user's rewards including total rewards and current leg balances",
     *     operationId="getUserRewardsSummary",
     *     tags={"Rewards"},
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
     *         description="Rewards summary retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="total_rewards", type="number", format="float", example=1500.00, description="Total rewards received"),
     *             @OA\Property(property="current_leg_balances", type="object",
     *                 @OA\Property(property="leg_a", type="number", format="float", example=1500.00, description="Current balance of leg A"),
     *                 @OA\Property(property="leg_b", type="number", format="float", example=1500.00, description="Current balance of leg B"),
     *                 @OA\Property(property="leg_c", type="number", format="float", example=1500.00, description="Current balance of leg C")
     *             ),
     *             @OA\Property(property="last_reward_date", type="string", format="date", example="2024-02-19", description="Date of last reward received")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     )
     * )
     */
    public function getUserRewardsSummary($userId)
    {
        // Verify user exists
        $user = User::where('user_id', $userId)->firstOrFail();

        // Get latest reward record for current leg balances
        $latestReward = Reward::where('user_id', $userId)
            ->latest()
            ->first();

        // Get total rewards
        $totalRewards = Reward::where('user_id', $userId)
            ->where('is_rewarded', true)
            ->sum('reward_amount');

        // Get last reward date
        $lastReward = Reward::where('user_id', $userId)
            ->where('is_rewarded', true)
            ->latest()
            ->first();

        return response()->json([
            'total_rewards' => $totalRewards,
            'current_leg_balances' => [
                'leg_a' => $latestReward ? $latestReward->leg_a_balance : 0,
                'leg_b' => $latestReward ? $latestReward->leg_b_balance : 0,
                'leg_c' => $latestReward ? $latestReward->leg_c_balance : 0
            ],
            'last_reward_date' => $lastReward && $lastReward->completed_at ? $lastReward->completed_at->format('Y-m-d') : null
        ]);
    }
} 