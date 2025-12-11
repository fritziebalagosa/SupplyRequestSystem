-- Check if the notifications table needs the is_read column
-- If it was created with 'read' instead of 'is_read', add the correct column

-- First, let's see what columns exist
DESCRIBE notifications;

-- If is_read doesn't exist, add it (and keep 'read' for backward compatibility if it exists)
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0;

-- If 'read' column exists, copy its values to is_read
UPDATE notifications SET is_read = `read` WHERE `read` IS NOT NULL;

-- If 'read' column exists, we can optionally drop it later (but keep for now for safety)
-- ALTER TABLE notifications DROP COLUMN `read`;

-- Add read_at column if it doesn't exist
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS read_at TIMESTAMP NULL DEFAULT NULL;

-- Update read_at when marking as read
UPDATE notifications SET read_at = NOW() WHERE is_read = 1 AND read_at IS NULL;
