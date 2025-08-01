<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Leg Balance",
 *     description="API Endpoints for managing user leg balances"
 * )
 */
class LegBalanceController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/leg-balances/{user_id}",
     *     summary="Get user's leg balances",
     *     description="Calculate and return the balance for each leg based on completed deposits",
     *     operationId="getLegBalances",
     *     tags={"Leg Balance"},
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
     *         description="Leg balances retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="leg_a_balance", type="number", format="float", example=1500.00),
     *             @OA\Property(property="leg_b_balance", type="number", format="float", example=1200.00),
     *             @OA\Property(property="leg_c_balance", type="number", format="float", example=1800.00),
     *             @OA\Property(property="total_balance", type="number", format="float", example=4500.00)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     )
     * )
     */
    public function getLegBalances($userId)
    {
        $user = User::where('user_id', $userId)->firstOrFail();

        // Get direct referrals ordered by creation date
        $referrals = $user->referrals()->orderBy('created_at')->get();

        // Initialize leg balances
        $legABalance = 0;
        $legBBalance = 0;
        $legCBalance = 0;

        // Calculate balance for each leg based on referral order
        foreach ($referrals as $index => $referral) {
            $legBalance = $this->calculateReferralBalance($referral);
            
            switch ($index) {
                case 0:
                    $legABalance = $legBalance;
                    break;
                case 1:
                    $legBBalance = $legBalance;
                    break;
                case 2:
                    $legCBalance = $legBalance;
                    break;
            }
        }

        return response()->json([
            'leg_a_balance' => $legABalance,
            'leg_b_balance' => $legBBalance,
            'leg_c_balance' => $legCBalance,
            'total_balance' => $legABalance + $legBBalance + $legCBalance
        ]);
    }

    private function calculateReferralBalance($referral)
    {
        // Get all completed deposits for this referral
        $deposits = $referral->deposits()
            ->where('status', 'completed')
            ->sum('amount');
        
        // Get all sub-referrals recursively
        $subReferrals = $referral->referrals;
        foreach ($subReferrals as $subReferral) {
            $deposits += $this->calculateReferralBalance($subReferral);
        }

        return $deposits;
    }
}