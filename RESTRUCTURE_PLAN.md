# Database Restructure Plan: User-Title State Management

## Problem
Currently, title state (want/watching/watched/stopped), rating, and comments are stored in `list_items` table, which creates data inconsistencies when the same title appears in multiple lists owned by the same user. Also, the watched list concept is redundant since watched status can be derived from user title state.

## Solution
Create a cleaner separation between:
1. **List membership** - Which titles are in which lists (list_items table)  
2. **User title state** - User's personal rating/state/comment for each title (new user_titles table)

## Changes Made

### 1. New Database Structure
- **Created**: `user_titles` table for user-specific title state
- **Modified**: `list_items` simplified to only store list membership
- **Removed**: `is_watched_list` functionality from lists

### 2. New Model Classes
- **Created**: `UserTitle.php` - Handles user-title state relationships
- **Updated**: `ListItem.php` - Simplified to handle only list membership
- **Updated**: `ListModel.php` - Removed watched list functionality

### 3. Database Migration Script
- **Created**: `migration_new_structure.sql` - Complete migration script

## What Still Needs to Be Done

### API Endpoints to Update:
1. `POST /api/titles/{tmdb_id}/{media_type}/add-to-list` - Should separate list addition from state setting
2. `POST /api/titles/update/{title_id}` - Should use UserTitle::setState()
3. `GET /api/titles/{title_id}/status` - Should join with user_titles
4. `GET /api/lists/{list_id}/items` - Should join with user_titles for state info
5. Add new endpoints for title state management

### Frontend Updates:
1. Update title detail pages to work with separated state management
2. Update list views to show user state alongside list membership
3. Remove watched list filtering logic (use state filtering instead)
4. Update search functionality to work with new structure

### Key Benefits After Migration:
1. **Data Consistency** - One source of truth for user title state
2. **Simplified Logic** - No more watched list complexity  
3. **Cleaner Code** - Clear separation of concerns
4. **Better UX** - Consistent state across all lists
5. **Easier Features** - Can add features like "recently watched" without list dependency

## Migration Steps:
1. Run `migration_new_structure.sql` to create new structure and migrate data
2. Update API endpoints to use new UserTitle model
3. Update frontend JavaScript to work with new data structure
4. Test all functionality thoroughly
5. Deploy changes

## Rollback Plan:
If needed, can recreate old structure from user_titles data since all information is preserved during migration.