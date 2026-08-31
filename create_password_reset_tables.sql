-- Create password_resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    username VARCHAR(255) NOT NULL,
    otp VARCHAR(10) NOT NULL,
    status VARCHAR(50) NOT NULL,
    attempts INT DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_username (username),
    INDEX idx_status (status)
);

-- Add activity_type to login_activities
ALTER TABLE login_activities 
ADD COLUMN IF NOT EXISTS activity_type VARCHAR(50) DEFAULT 'login' AFTER reason;

-- Verify
SELECT 'Tables created/updated successfully' as status;
