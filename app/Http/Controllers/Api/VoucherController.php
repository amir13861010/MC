<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * @OA\Tag(
 *     name="Vouchers",
 *     description="API Endpoints for managing vouchers"
 * )
 */

/**
 * @OA\Schema(
 *     schema="Voucher",
 *     type="object",
 *     title="Voucher",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="gainprofit", type="number", format="float", example=500.00),
 *     @OA\Property(property="amount", type="number", format="float", example=500.00),
 *     @OA\Property(property="user_id", type="string", example="1"),
 *     @OA\Property(property="code", type="string", example="500MC123456U1234567890FRD123456"),
 *     @OA\Property(property="status", type="string", enum={"active", "inactive"}, example="active"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-01T00:00:00.000000Z")
 * )
 */
/**
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     @OA\Property(property="message", type="string", example="Error message here")
 * )
 */
class VoucherController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/vouchers",
     *     summary="Get list of vouchers",
     *     tags={"Vouchers"},
     *     @OA\Response(
     *         response=200,
     *         description="List of vouchers",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $vouchers = Voucher::with('user')->latest()->paginate(10);
        return response()->json($vouchers);
    }

    /**
     * @OA\Post(
     *     path="/api/vouchers",
     *     summary="Create a new voucher",
     *     tags={"Vouchers"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "user_id"},
     *             @OA\Property(property="amount", type="number", format="float", example=500),
     *             @OA\Property(property="user_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Voucher created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voucher created successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error or insufficient balance",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'user_id' => 'required|exists:users,user_id'
        ]);

        if ($validated['amount'] < 300) {
            return response()->json([
                'message' => 'The minimum amount to create a voucher is $300.'
            ], 422);
        }

        $user = User::where('user_id', $validated['user_id'])->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        // اگر کاربر ادمین نباشد، چک موجودی gain_profit انجام شود
        if ($user->role !== 'admin') {
            if ($validated['amount'] > $user->gain_profit) {
                return response()->json([
                    'message' => 'Insufficient gain profit balance.'
                ], 422);
            }

            // کاهش موجودی gain_profit فقط برای کاربران غیر ادمین
            $user->gain_profit -= $validated['amount'];
            $user->save();
        }

        $voucherData = [
            'gainprofit' => $validated['amount'],
            'amount' => $validated['amount'],
            'user_id' => $validated['user_id'],
            'status' => 'active',
            'code' => Voucher::generateUniqueCode($validated['amount'])
        ];
        $voucher = Voucher::create($voucherData);

        return response()->json([
            'message' => 'Voucher created successfully',
            'data' => $voucher
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/vouchers/{id}",
     *     summary="Get voucher details",
     *     tags={"Vouchers"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Voucher ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voucher details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voucher not found"
     *     )
     * )
     */
    public function show(Voucher $voucher)
    {
        return response()->json(['data' => $voucher]);
    }

    /**
     * @OA\Put(
     *     path="/api/vouchers/{id}",
     *     summary="Update voucher",
     *     tags={"Vouchers"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Voucher ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"gainprofit", "amount", "user_id", "status"},
     *             @OA\Property(property="gainprofit", type="number", format="float", example=500),
     *             @OA\Property(property="amount", type="number", format="float", example=500),
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive"}, example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voucher updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voucher updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function update(Request $request, Voucher $voucher)
    {
        $validated = $request->validate([
            'gainprofit' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'user_id' => 'required|exists:users,user_id',
            'status' => 'required|in:active,inactive'
        ]);

        $voucher->update($validated);

        return response()->json([
            'message' => 'Voucher updated successfully',
            'data' => $voucher
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/vouchers/{id}",
     *     summary="Delete voucher",
     *     tags={"Vouchers"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Voucher ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voucher deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voucher deleted successfully")
     *         )
     *     )
     * )
     */
    public function destroy(Voucher $voucher)
    {
        $voucher->delete();
        return response()->json(['message' => 'Voucher deleted successfully']);
    }

    /**
     * @OA\Get(
     *     path="/api/vouchers/user/{user_id}",
     *     summary="Get vouchers by user ID",
     *     tags={"Vouchers"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of vouchers for the user",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function getUserVouchers($user_id)
    {
        $vouchers = Voucher::where('user_id', $user_id)->latest()->get();
        return response()->json(['data' => $vouchers]);
    }

    /**
     * @OA\Get(
     *     path="/api/vouchers/code/{code}",
     *     summary="Get voucher by code",
     *     tags={"Vouchers"},
     *     @OA\Parameter(
     *         name="code",
     *         in="path",
     *         required=true,
     *         description="Voucher unique code",
     *         @OA\Schema(type="string", example="ABC123XYZ")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voucher details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voucher not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function getVoucherByCode($code)
    {
        $voucher = Voucher::where('code', $code)->first();
        if (!$voucher) {
            return response()->json(['message' => 'Voucher not found'], 404);
        }
        return response()->json(['data' => $voucher]);
    }

    /**
     * Redeem a voucher by code
     *
     * @OA\Post(
     *     path="/api/vouchers/redeem",
     *     summary="Redeem a voucher by code",
     *     tags={"Vouchers"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", example="ABC123XYZ")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voucher redeemed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voucher redeemed successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voucher not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Voucher is not active",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function redeemVoucher(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $voucher = Voucher::where('code', $request->code)->first();
        if (!$voucher) {
            return response()->json([
                'message' => 'Voucher not found.'
            ], 404);
        }
        if ($voucher->status !== 'active') {
            return response()->json([
                'message' => 'Voucher is not active.'
            ], 422);
        }
        $voucher->status = 'inactive';
        $voucher->save();

        return response()->json([
            'message' => 'Voucher redeemed successfully.',
            'data' => $voucher
        ]);
    }
}
