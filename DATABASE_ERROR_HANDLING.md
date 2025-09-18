# Database Error Handling System

This system provides graceful handling of database connection issues, showing user-friendly error messages instead of technical errors.

## Features

### 1. Global Exception Handler
- Located in `bootstrap/app.php`
- Catches database connection exceptions globally
- Returns appropriate error responses for both API and web requests

### 2. Database Connection Middleware
- Located in `app/Http/Middleware/DatabaseConnectionMiddleware.php`
- Tests database connection before processing requests
- Prevents requests from reaching controllers when database is unavailable

### 3. Controller-Level Error Handling
- Trait: `app/Traits/HandlesDatabaseErrors.php`
- Can be used in controllers for additional error handling
- Wraps database operations with error handling

### 4. Error Response Format

All database connection errors return the same JSON format as your login API:

```json
{
    "message": "OMEGA, Updating assets"
}
```

This ensures consistency across all API endpoints and provides a unified error experience.

## Detected Error Types

The system catches the following database connection errors:
- Connection refused
- SQLSTATE[HY000] errors
- "could not connect to server"
- "No connection could be made"
- "Connection timed out"
- "Connection reset by peer"
- "Lost connection to MySQL server"
- "MySQL server has gone away"
- "Can't connect to MySQL server"
- Various SQLSTATE error codes (08006, 08001, 08003, 08004, 08007)

## Usage

### Automatic Handling
The system works automatically for all requests. No additional configuration needed.

### Manual Usage in Controllers
```php
use App\Traits\HandlesDatabaseErrors;

class YourController extends Controller
{
    use HandlesDatabaseErrors;
    
    public function yourMethod()
    {
        return $this->handleDatabaseOperation(function () {
            // Your database operations here
            $user = User::find(1);
            return response()->json($user);
        });
    }
}
```

## Testing

Test the error handling with:
- API: `GET /api/test-db-error`
- Web: Visit any page when database is down

## Configuration

The middleware is automatically registered for both web and API routes in `bootstrap/app.php`.

## Error Message Customization

To customize the error message, update the message in:
- `bootstrap/app.php` (global exception handler)
- `app/Http/Middleware/DatabaseConnectionMiddleware.php` (middleware)
- `app/Traits/HandlesDatabaseErrors.php` (trait)
