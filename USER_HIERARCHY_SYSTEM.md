# User Hierarchy History System

## Overview
This system tracks when users become subordinates of other users, providing a complete history of user relationships with timestamps.

## Features

### 1. Automatic History Tracking
- When a user registers with a `friend_id`, a hierarchy history record is automatically created
- Tracks when users joined and left (if applicable) their parent's hierarchy
- Maintains notes for each relationship change

### 2. API Endpoints

#### Get User's Hierarchy History
```
GET /api/users/{user_id}/hierarchy-history
```
Shows when a user became a subordinate of other users.

**Parameters:**
- `status` (optional): Filter by `active`, `inactive`, or `all`
- `limit` (optional): Number of records to return (default: 20)

**Response:**
```json
{
  "user_id": "MC123456",
  "history": [
    {
      "id": 1,
      "parent_user_id": "MC654321",
      "parent_name": "John Doe",
      "joined_at": "2024-01-15T10:30:00Z",
      "left_at": null,
      "notes": "User registered with referral",
      "duration_days": 45
    }
  ],
  "total": 1
}
```

#### Get User's Subordinates History
```
GET /api/users/{user_id}/subordinates-history
```
Shows when other users became subordinates of this user.

**Parameters:**
- `status` (optional): Filter by `active`, `inactive`, or `all`
- `start_date` (optional): Start date for filtering (YYYY-MM-DD)
- `end_date` (optional): End date for filtering (YYYY-MM-DD)
- `limit` (optional): Number of records to return (default: 20)

**Response:**
```json
{
  "user_id": "MC123456",
  "subordinates": [
    {
      "id": 1,
      "subordinate_user_id": "MC789012",
      "subordinate_name": "Jane Smith",
      "subordinate_email": "jane@example.com",
      "joined_at": "2024-01-15T10:30:00Z",
      "left_at": null,
      "notes": "User registered with referral",
      "duration_days": 45,
      "status": "active"
    }
  ],
  "summary": {
    "total_subordinates": 3,
    "active_subordinates": 2,
    "inactive_subordinates": 1
  },
  "total": 1
}
```

#### Change User's Parent
```
POST /api/users/{user_id}/change-parent
```
Move a user to a different parent in the hierarchy.

**Request Body:**
```json
{
  "new_parent_user_id": "MC654321",
  "notes": "User transferred to new parent"
}
```

**Response:**
```json
{
  "message": "User parent changed successfully",
  "old_parent_user_id": "MC111111",
  "new_parent_user_id": "MC654321",
  "changed_at": "2024-01-15T10:30:00Z"
}
```

## Database Structure

### user_hierarchy_history Table
- `id`: Primary key
- `user_id`: The subordinate user
- `parent_user_id`: The parent user
- `joined_at`: When the relationship started
- `left_at`: When the relationship ended (null if active)
- `notes`: Additional notes about the relationship
- `created_at`, `updated_at`: Timestamps

## Setup Instructions

1. Run the migration:
```bash
php artisan migrate
```

2. Populate existing data (if you have existing user relationships):
```bash
php artisan hierarchy:populate-history
```

3. Force recreate history (if needed):
```bash
php artisan hierarchy:populate-history --force
```

## Usage Examples

### Example 1: Check when a user became a subordinate
```bash
curl -X GET "http://your-domain.com/api/users/MC123456/hierarchy-history"
```

### Example 2: See all subordinates of a user
```bash
curl -X GET "http://your-domain.com/api/users/MC123456/subordinates-history?status=active"
```

### Example 3: Change a user's parent
```bash
curl -X POST "http://your-domain.com/api/users/MC123456/change-parent" \
  -H "Content-Type: application/json" \
  -d '{"new_parent_user_id": "MC654321", "notes": "User transferred"}'
```

## Benefits

1. **Complete Audit Trail**: Track all hierarchy changes with timestamps
2. **Historical Analysis**: See how user relationships evolved over time
3. **Compliance**: Maintain records for regulatory requirements
4. **Analytics**: Analyze user growth patterns and relationship durations
5. **Flexibility**: Support for moving users between different parents

## Notes

- The system automatically creates history records when users register with a `friend_id`
- Users can only have one active parent at a time
- Maximum 3 active subordinates per parent (enforced during registration and parent changes)
- All hierarchy changes are logged with timestamps and notes 