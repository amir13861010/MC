<?php

namespace App\Http\Controllers;

use App\Models\WithdrawalRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Withdrawal Requests",
 *     description="API Endpoints for managing withdrawal requests"
 * )
 */
class WithdrawalRequestController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/withdrawal-requests",
     *     summary="Create a new withdrawal request",
     *     tags={"Withdrawal Requests"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "wallet_address", "amount_usd", "amount_btc"},
     *             @OA\Property(property="user_id", type="string", example="MC-123456"),
     *             @OA\Property(property="wallet_address", type="string", example="1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"),
     *             @OA\Property(property="amount_usd", type="number", format="float", example=100.00),
     *             @OA\Property(property="amount_btc", type="number", format="float", example=0.00123456),
     *             @OA\Property(property="comment", type="string", example="Monthly withdrawal")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Withdrawal request created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Withdrawal request created successfully"),
     *             @OA\Property(
     *                 property="withdrawal_request",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="withdrawal_id", type="string", example="abc123"),
     *                 @OA\Property(property="user_id", type="string", example="MC-123456"),
     *                 @OA\Property(property="wallet_address", type="string", example="1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"),
     *                 @OA\Property(property="amount_usd", type="number", example=100.00),
     *                 @OA\Property(property="amount_btc", type="number", example=0.00123456),
     *                 @OA\Property(property="comment", type="string", example="Monthly withdrawal"),
     *                 @OA\Property(property="status", type="string", example="in_process"),
     *                 @OA\Property(property="transaction_hash", type="string", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,user_id',
            'wallet_address' => 'required|string|max:255',
            'amount_usd' => 'required|numeric|min:0',
            'amount_btc' => 'required|numeric|min:0',
            'comment' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }
        if ($user->capital_profit < $request->amount_usd) {
            return response()->json([
                'message' => 'Insufficient capital profit balance'
            ], 400);
        }
        $user->capital_profit -= $request->amount_usd;
        $user->save();

        $withdrawalRequest = WithdrawalRequest::create([
            'user_id' => $request->user_id,
            'wallet_address' => $request->wallet_address,
            'amount_usd' => $request->amount_usd,
            'amount_btc' => $request->amount_btc,
            'comment' => $request->comment,
            'status' => WithdrawalRequest::STATUS_IN_PROCESS
        ]);

        return response()->json([
            'message' => 'Withdrawal request created successfully',
            'withdrawal_request' => $withdrawalRequest
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/withdrawal-requests/{user_id}",
     *     summary="Get user's withdrawal requests",
     *     tags={"Withdrawal Requests"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of user's withdrawal requests",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="withdrawal_requests",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="withdrawal_id", type="string", example="abc123"),
     *                     @OA\Property(property="user_id", type="string", example="MC-123456"),
     *                     @OA\Property(property="wallet_address", type="string", example="1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"),
     *                     @OA\Property(property="amount_usd", type="number", example=100.00),
     *                     @OA\Property(property="amount_btc", type="number", example=0.00123456),
     *                     @OA\Property(property="comment", type="string", example="Monthly withdrawal"),
     *                     @OA\Property(property="status", type="string", example="in_process"),
     *                     @OA\Property(property="transaction_hash", type="string", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function getUserRequests($userId)
    {
        $requests = WithdrawalRequest::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['withdrawal_requests' => $requests]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/withdrawal-requests/{request_id}/update-status",
     *     summary="Update withdrawal request status",
     *     tags={"Withdrawal Requests"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="request_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"in_queue", "in_process", "completed"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status updated successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Request not found"
     *     )
     * )
     */
    public function updateStatus(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:in_queue,in_process,completed'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $withdrawalRequest = WithdrawalRequest::where('withdrawal_id', $requestId)->firstOrFail();

        if (!$withdrawalRequest->canBeUpdated()) {
            return response()->json([
                'message' => 'Cannot update status of completed request'
            ], 400);
        }

        $withdrawalRequest->update([
            'status' => $request->status
        ]);

        return response()->json([
            'message' => 'Status updated successfully',
            'withdrawal_request' => $withdrawalRequest
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/withdrawal-requests/{request_id}/add-transaction-hash",
     *     summary="Add transaction hash to withdrawal request",
     *     tags={"Withdrawal Requests"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="request_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"transaction_hash"},
     *             @OA\Property(property="transaction_hash", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transaction hash added successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Request not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function addTransactionHash(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'transaction_hash' => 'required|string|max:255'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $withdrawalRequest = WithdrawalRequest::where('withdrawal_id', $requestId)->firstOrFail();
    
        $withdrawalRequest->update([
            'transaction_hash' => $request->transaction_hash
        ]);
    
        return response()->json([
            'message' => 'Transaction hash added successfully',
            'withdrawal_request' => $withdrawalRequest
        ]);
    }

    /**
     * Cancel a withdrawal request (user or admin)
     * Only possible if status is in_process
     */
    public function cancel(Request $request, $requestId)
    {
        $withdrawalRequest = WithdrawalRequest::where('withdrawal_id', $requestId)->firstOrFail();

        if ($withdrawalRequest->status !== WithdrawalRequest::STATUS_IN_PROCESS) {
            return response()->json([
                'message' => 'Only requests in in_process status can be cancelled'
            ], 400);
        }

        $user = User::where('user_id', $withdrawalRequest->user_id)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // Refund the amount
        $user->capital_profit += $withdrawalRequest->amount_usd;
        $user->save();

        $withdrawalRequest->update([
            'status' => WithdrawalRequest::STATUS_CANCELLED
        ]);

        return response()->json([
            'message' => 'Withdrawal request cancelled and amount refunded',
            'withdrawal_request' => $withdrawalRequest
        ]);
    }

    /**
     * Admin can change status to any value (even from completed to in_queue, etc.)
     */
    public function adminChangeStatus(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:in_queue,in_process,completed,cancelled'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $withdrawalRequest = WithdrawalRequest::where('withdrawal_id', $requestId)->firstOrFail();
        $oldStatus = $withdrawalRequest->status;
        $newStatus = $request->status;

        // اگر وضعیت از cancelled به غیر از cancelled تغییر کند، مبلغ باید دوباره کم شود (در صورت کافی بودن موجودی)
        if ($oldStatus === WithdrawalRequest::STATUS_CANCELLED && $newStatus !== WithdrawalRequest::STATUS_CANCELLED) {
            $user = User::where('user_id', $withdrawalRequest->user_id)->first();
            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }
            if ($user->capital_profit < $withdrawalRequest->amount_usd) {
                return response()->json([
                    'message' => 'Insufficient capital profit balance to re-activate request'
                ], 400);
            }
            $user->capital_profit -= $withdrawalRequest->amount_usd;
            $user->save();
        }
        // اگر وضعیت به cancelled تغییر کند، مبلغ باید به کاربر بازگردد
        if ($oldStatus !== WithdrawalRequest::STATUS_CANCELLED && $newStatus === WithdrawalRequest::STATUS_CANCELLED) {
            $user = User::where('user_id', $withdrawalRequest->user_id)->first();
            if ($user) {
                $user->capital_profit += $withdrawalRequest->amount_usd;
                $user->save();
            }
        }

        $withdrawalRequest->update([
            'status' => $newStatus
        ]);

        return response()->json([
            'message' => 'Status changed successfully',
            'withdrawal_request' => $withdrawalRequest
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/withdrawal-requests",
     *     summary="Get all withdrawal requests (admin only)",
     *     tags={"Withdrawal Requests"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", enum={"in_queue", "in_process", "completed"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of all withdrawal requests",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="withdrawal_requests",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="withdrawal_id", type="string", example="abc123"),
     *                     @OA\Property(property="user_id", type="string", example="MC-123456"),
     *                     @OA\Property(property="wallet_address", type="string", example="1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"),
     *                     @OA\Property(property="amount_usd", type="number", example=100.00),
     *                     @OA\Property(property="amount_btc", type="number", example=0.00123456),
     *                     @OA\Property(property="comment", type="string", example="Monthly withdrawal"),
     *                     @OA\Property(property="status", type="string", example="in_process"),
     *                     @OA\Property(property="transaction_hash", type="string", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time"),
     *                     @OA\Property(
     *                         property="user",
     *                         type="object",
     *                         @OA\Property(property="user_id", type="string", example="MC-123456"),
     *                         @OA\Property(property="name", type="string", example="John Doe"),
     *                         @OA\Property(property="email", type="string", example="john@example.com")
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function adminIndex(Request $request)
    {
        $query = WithdrawalRequest::with('user');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['withdrawal_requests' => $requests]);
    }
} 