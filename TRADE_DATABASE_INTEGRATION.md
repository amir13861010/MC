# Trade Database Integration

## Overview
This document explains how the trade files are now connected to the database. The system stores trade JSON files in `storage/app/private/trades` and maintains database records for tracking and management.

## File Storage Structure
- **Storage Path**: `storage/app/private/trades/`
- **File Naming**: `{user_id}_{year}_{month}_{level}.json`
- **Example**: `MC123456_2024_7_1.json`

## Database Schema
The `trades` table contains:
- `id` - Primary key
- `user_id` - Foreign key to users table
- `file_path` - Path to the JSON file relative to storage
- `expires_at` - Expiration timestamp
- `is_active` - Boolean flag for active status
- `last_processed_at` - Last processing timestamp
- `created_at` / `updated_at` - Timestamps

## API Endpoints

### Core Trade Operations
- `POST /api/trade` - Create new trade and store file
- `GET /api/trade/{user_id}` - Get trade result for user
- `POST /api/trade/{user_id}/renew` - Renew trade for user
- `POST /api/trade/{user_id}/process-daily-profit` - Process daily profit

### Trade Management
- `GET /api/trades` - Get all trades from database
- `GET /api/trades/active` - Get only active trades
- `GET /api/trades/stats` - Get trade statistics
- `GET /api/trade/{user_id}/expiration` - Check trade expiration

### Database Sync & Testing
- `POST /api/trades/sync-files` - Sync existing files with database
- `GET /api/trades/test-connection` - Test database and storage connection

## Key Features

### 1. Automatic Directory Creation
The system automatically creates the `trades` directory if it doesn't exist.

### 2. Database-File Synchronization
- Files are stored in `storage/app/private/trades/`
- Database records track file paths and metadata
- Sync functionality to connect existing files with database

### 3. File Naming Convention
Files are named with user_id, year, month, and level for easy identification:
```
{user_id}_{year}_{month}_{level}.json
```

### 4. Expiration Management
- Trades expire after 30 days by default
- Automatic status tracking (active/expired)
- Renewal functionality

### 5. Statistics and Monitoring
- Total trades count
- Active vs expired trades
- File existence verification
- Storage path information

## Usage Examples

### Create a New Trade
```bash
POST /api/trade
{
    "user_id": "MC123456",
    "year": 2024,
    "month": 7,
    "level": 1
}
```

### Get All Trades
```bash
GET /api/trades
```

### Sync Existing Files
```bash
POST /api/trades/sync-files
```

### Test Connection
```bash
GET /api/trades/test-connection
```

## Artisan Commands

### Setup Storage
```bash
php artisan trade:setup-storage
```

### Setup Storage with Sync
```bash
php artisan trade:setup-storage --sync
```

## File Storage Configuration
The system uses Laravel's `local` disk configured in `config/filesystems.php`:
```php
'local' => [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'serve' => true,
    'throw' => false,
    'report' => false,
],
```

## Database Relationships
- `Trade` belongs to `User` (via `user_id`)
- Foreign key constraint ensures data integrity
- Cascade delete removes trade records when user is deleted

## Error Handling
- File existence verification
- Database connection testing
- Storage path validation
- User existence checks

## Security Considerations
- Files stored in private directory (not publicly accessible)
- Database records for audit trail
- User authentication required for API access
- File path validation to prevent directory traversal 