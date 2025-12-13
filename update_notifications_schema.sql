-- Update notifications table to allow NULL request_id for system notifications
-- This migration allows low stock alerts and other system notifications

-- First, drop the foreign key constraint
ALTER TABLE notifications DROP FOREIGN KEY notifications_ibfk_2;

-- Then modify the column to allow NULL
ALTER TABLE notifications MODIFY COLUMN request_id INT DEFAULT NULL;

-- Re-add the foreign key constraint with ON DELETE SET NULL
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_request_id 
FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL;

-- Add the type column if it doesn't exist
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'general';

-- Change is_read to read if it exists and is the old name
ALTER TABLE notifications CHANGE COLUMN is_read `read` TINYINT(1) DEFAULT 0;
