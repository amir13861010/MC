#!/bin/bash
cd /path/to/your/project
php artisan trade:check-expired >> storage/logs/cron.log 2>&1 