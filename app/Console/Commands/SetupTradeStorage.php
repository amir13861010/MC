<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Trade;
use App\Models\User;

class SetupTradeStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:setup-storage {--sync : Sync existing files with database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup trade storage directory and optionally sync existing files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Setting up trade storage...');

        // Create trades directory if it doesn't exist
        if (!Storage::disk('local')->exists('trades')) {
            Storage::disk('local')->makeDirectory('trades');
            $this->info('✓ Created trades directory at storage/app/private/trades');
        } else {
            $this->info('✓ Trades directory already exists at storage/app/private/trades');
        }

        // Check existing files
        $files = Storage::disk('local')->files('trades');
        $jsonFiles = array_filter($files, function($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'json';
        });

        $this->info("Found " . count($jsonFiles) . " JSON files in trades directory");

        if ($this->option('sync') && count($jsonFiles) > 0) {
            $this->info('Syncing files with database...');
            $this->syncFiles($jsonFiles);
        }

        $this->info('Trade storage setup completed!');
    }

    private function syncFiles($jsonFiles)
    {
        $syncedCount = 0;
        $bar = $this->output->createProgressBar(count($jsonFiles));
        $bar->start();

        foreach ($jsonFiles as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            
            // Extract user_id from filename (assuming format: user_id_year_month_level.json)
            $parts = explode('_', $filename);
            if (count($parts) >= 1) {
                $user_id = $parts[0];
                
                // Check if user exists
                $user = User::where('user_id', $user_id)->first();
                if ($user) {
                    // Check if trade record exists in database
                    $existingTrade = Trade::where('user_id', $user_id)->first();
                    
                    if (!$existingTrade) {
                        // Create new trade record
                        Trade::create([
                            'user_id' => $user_id,
                            'file_path' => $file,
                            'expires_at' => now()->addDays(30),
                            'is_active' => true,
                            'last_processed_at' => null,
                        ]);
                        $syncedCount++;
                    } else {
                        // Update existing trade record with current file path
                        $existingTrade->file_path = $file;
                        $existingTrade->save();
                    }
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✓ Synced {$syncedCount} new trade records with database");
    }
} 