-- Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_id INT NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create release_proofs table for receipt images
CREATE TABLE IF NOT EXISTS release_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add status column to requests table if it doesn't exist
ALTER TABLE requests 
ADD COLUMN IF NOT EXISTS status ENUM('pending', 'approved', 'rejected', 'completed', 'returned') DEFAULT 'pending';

-- Add approved_quantity column to request_items table if it doesn't exist
ALTER TABLE request_items 
ADD COLUMN IF NOT EXISTS approved_quantity INT DEFAULT NULL;

-- Create index for better performance on notifications
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_request_id ON notifications(request_id);
CREATE INDEX idx_notifications_created_at ON notifications(created_at);

-- Create index for better performance on release_proofs
CREATE INDEX idx_release_proofs_request_id ON release_proofs(request_id);
CREATE INDEX idx_release_proofs_user_id ON release_proofs(user_id);
