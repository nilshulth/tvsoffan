-- Migration: Remove default list concept
-- Run this on existing databases to remove the is_default column

-- Remove the index first
DROP INDEX idx_is_default ON lists;

-- Remove the is_default column
ALTER TABLE lists DROP COLUMN is_default;