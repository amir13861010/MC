<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="User Hierarchy",
 *     description="API Endpoints for user hierarchy management"
 * )
 */
class UserHierarchyController extends Controller
{
    /**
     * بازگشت سلسله مراتب کاربران به صورت بازگشتی
     */
    private function getUserHierarchy($user)
    {
        // استفاده از رابطه Eloquent برای بهینه‌سازی کوئری‌ها
        $user->load('recursiveSubUsers');
        
        return [
            'user_id' => $user->user_id,
            'sub_users' => $user->recursiveSubUsers->map(function($subUser) {
                return $this->getUserHierarchy($subUser);
            })->toArray()
        ];
    }

    /**
     * @OA\Get(
     *     path="/api/users/hierarchy",
     *     summary="Get all users with their hierarchy",
     *     tags={"User Hierarchy"},
     *     @OA\Response(
     *         response=200,
     *         description="List of users with their hierarchy",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(
     *                     property="sub_users",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="user_id", type="string", example="MC34235"),
     *                         @OA\Property(
     *                             property="sub_users",
     *                             type="array",
     *                             @OA\Items(
     *                                 type="object",
     *                                 @OA\Property(property="user_id", type="string", example="MC34236")
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $mainUsers = User::whereNull('friend_id')->get();
        $hierarchy = $mainUsers->map(function($user) {
            return $this->getUserHierarchy($user);
        });
        return response()->json($hierarchy);
    }

    /**
     * @OA\Get(
     *     path="/api/users/{user_id}/sub-users",
     *     summary="Get sub-users of a specific user",
     *     tags={"User Hierarchy"},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="User ID to get sub-users for",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of sub-users with full hierarchy",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="user_id", type="string", example="MC34234"),
     *             @OA\Property(
     *                 property="sub_users",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="user_id", type="string", example="MC34235"),
     *                     @OA\Property(
     *                         property="sub_users",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="user_id", type="string", example="MC34236")
     *                         )
     *                     )
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
    public function getSubUsers($userId)
    {
        $user = User::where('user_id', $userId)->firstOrFail();
        
        // بازگشت سلسله مراتب کامل
        $hierarchy = $this->getUserHierarchy($user);
        
        return response()->json($hierarchy);
    }
}