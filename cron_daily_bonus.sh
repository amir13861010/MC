#!/bin/bash

# Set the project directory
PROJECT_DIR="/home/amir/Documents/bm"

# Change to project directory
cd "$PROJECT_DIR"

# Run the daily bonus calculation
php artisan bonus:calculate-daily >> storage/logs/cron-daily-bonus.log 2>&1

# Also run the job version as backup
php artisan queue:work --once --queue=default >> storage/logs/cron-daily-bonus-job.log 2>&1

echo "Daily bonus calculation completed at $(date)" >> storage/logs/cron-daily-bonus.log 