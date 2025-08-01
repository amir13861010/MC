<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserHierarchySeeder extends Seeder
{
    public function run(): void
    {
        // Create a top-level user (no parent)
        $topUser1 = User::create([
            'name' => 'Ali Rezaei',
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
            'email' => 'ali@example.com',
            'email_verified_at' => now(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'remember_token' => \Illuminate\Support\Str::random(10),
            'user_id' => User::generateUniqueUserId(),
            'deposit_balance' => 5000.00,
            'country' => 'Iran',
            'mobile' => '+989111111111',
            'friend_id' => null,
        ]);

        // Create a second top-level user
        $topUser2 = User::create([
            'name' => 'Sara Ahmadi',
            'first_name' => 'Sara',
            'last_name' => 'Ahmadi',
            'email' => 'sara@example.com',
            'email_verified_at' => now(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'remember_token' => \Illuminate\Support\Str::random(10),
            'user_id' => User::generateUniqueUserId(),
            'deposit_balance' => 3000.00,
            'country' => 'Iran',
            'mobile' => '+989122222222',
            'friend_id' => null,
        ]);

        // Create 6 sub-users for topUser1
        $subUsers1 = [];
        for ($i = 0; $i < 6; $i++) {
            $subUsers1[] = User::factory()->create([
                'user_id' => User::generateUniqueUserId(),
                'friend_id' => $topUser1->user_id,
                'email' => 'sub1_' . $i . '@example.com',
            ]);
        }

        // Create 4 sub-users for topUser2
        $subUsers2 = [];
        for ($i = 0; $i < 4; $i++) {
            $subUsers2[] = User::factory()->create([
                'user_id' => User::generateUniqueUserId(),
                'friend_id' => $topUser2->user_id,
                'email' => 'sub2_' . $i . '@example.com',
            ]);
        }

        // Create 3 sub-users for one of subUsers1 (nested hierarchy)
        for ($i = 0; $i < 3; $i++) {
            User::factory()->create([
                'user_id' => User::generateUniqueUserId(),
                'friend_id' => $subUsers1[0]->user_id,
                'email' => 'sub1_0_' . $i . '@example.com',
            ]);
        }

        // Create 5 independent users (no parent)
        for ($i = 0; $i < 5; $i++) {
            User::factory()->create([
                'user_id' => User::generateUniqueUserId(),
                'friend_id' => null,
                'email' => 'independent_' . $i . '@example.com',
            ]);
        }
    }
} 