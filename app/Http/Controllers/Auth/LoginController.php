<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="API Endpoints for user authentication"
 * )
 */
class LoginController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     summary="Login user",
     *     description="Login user with username (MC-XXXXXX) and password",
     *     operationId="login",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username", "password"},
     *             @OA\Property(property="username", type="string", example="MC34234"),
     *             @OA\Property(property="password", type="string", example="a1b2c3d4")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC342345"),
     *                 @OA\Property(property="first_name", type="string", example="John"),
     *                 @OA\Property(property="last_name", type="string", example="Doe"),
     *                 @OA\Property(property="email", type="string", example="john@example.com"),
     *                 @OA\Property(property="phone", type="string", example="+989123456789"),
     *                 @OA\Property(property="country", type="string", example="Iran"),
     *                 @OA\Property(property="deposit_balance", type="number", format="float", example=1000.00)
     *             ),
     *             @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid credentials")
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        // Validate request
        $request->validate([
            'username' => 'required|string|regex:/^MC\d{6,}$/',
            'password' => 'required|string'
        ]);

        // Find user by user_id
        $user = User::where('user_id', $request->username)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials']
            ]);
        }

        // Generate JWT token
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->mobile,
                'country' => $user->country,
                'deposit_balance' => $user->deposit_balance,
                'authenticator_active' => (bool) $user->two_factor_enabled,
                'suspend' => (bool) $user->suspend
            ],
            'token' => $token
        ]);
    }

    /**
     * Decode JWT token and return user_id, expiration, and active status
     *
     * @OA\Post(
     *     path="/api/auth/decode-token",
     *     summary="Decode JWT token",
     *     description="Decode JWT token and return user_id, expiration, and active status",
     *     operationId="decodeToken",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token"},
     *             @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Decoded token info",
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="string", example="MC34234"),
     *             @OA\Property(property="exp", type="integer", example=1717267200),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid token",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid token")
     *         )
     *     )
     * )
     */
    public function decodeToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        try {
            $payload = JWTAuth::setToken($request->token)->getPayload();
            $user_numeric_id = $payload->get('sub');
            $exp = $payload->get('exp');
            $active = ($exp > time());

            // Find user by numeric id
            $user = User::find($user_numeric_id);

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'user_id' => $user->user_id, // MC-XXXXXX format
                'exp' => $exp,
                'active' => $active,
                'authenticator_active' => (bool) $user->two_factor_enabled,
                'is_admin' => $user->role === 'admin',
                'suspend' => (bool) $user->suspend
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid token'
            ], 400);
        }
    }

    /**
     * Unsuspend a user by user_id
     *
     * @OA\Post(
     *     path="/api/auth/unsuspend",
     *     summary="Unsuspend user",
     *     description="Unsuspend a user by user_id (MC-XXXXXX)",
     *     operationId="unsuspendUser",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id"},
     *             @OA\Property(property="user_id", type="string", example="MC34234")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User unsuspended successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User unsuspended successfully")
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
    public function unsuspendUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string|regex:/^MC\\d{6,}$/'
        ]);

        $user = User::where('user_id', $request->user_id)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }
        $user->suspend = false;
        $user->save();
        return response()->json([
            'message' => 'User unsuspended successfully'
        ]);
    }
} 