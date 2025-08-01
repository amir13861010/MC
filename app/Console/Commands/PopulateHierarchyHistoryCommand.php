<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserHierarchyHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateHierarchyHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hierarchy:populate-history {--force : Force recreation of history}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate user hierarchy history table with existing user relationships';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to populate user hierarchy history...');

        if ($this->option('force')) {
            $this->warn('Force option detected. Clearing existing hierarchy history...');
            UserHierarchyHistory::truncate();
        }

        // Get all users with friend_id (subordinates)
        $usersWithParents = User::whereNotNull('friend_id')->get();
        
        $this->info("Found {$usersWithParents->count()} users with parent relationships.");

        $bar = $this->output->createProgressBar($usersWithParents->count());
        $bar->start();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($usersWithParents as $user) {
            // Check if history already exists for this user
            $existingHistory = UserHierarchyHistory::where('user_id', $user->user_id)
                ->where('parent_user_id', $user->friend_id)
                ->first();

            if ($existingHistory && !$this->option('force')) {
                $skippedCount++;
                $bar->advance();
                continue;
            }

            try {
                // Create hierarchy history record
                UserHierarchyHistory::create([
                    'user_id' => $user->user_id,
                    'parent_user_id' => $user->friend_id,
                    'joined_at' => $user->created_at, // Use user creation date as join date
                    'notes' => 'Migrated from existing user relationship'
                ]);

                $createdCount++;
            } catch (\Exception $e) {
                $this->error("Error creating history for user {$user->user_id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Hierarchy history population completed!");
        $this->info("Created: {$createdCount} records");
        $this->info("Skipped: {$skippedCount} records (already existed)");
        
        // Show summary statistics
        $totalHistory = UserHierarchyHistory::count();
        $activeHistory = UserHierarchyHistory::whereNull('left_at')->count();
        
        $this->info("Total hierarchy history records: {$totalHistory}");
        $this->info("Active relationships: {$activeHistory}");
    }
} 