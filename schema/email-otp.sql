-- Email verification OTP for signup.
-- mail-otp.php also applies these changes automatically on first use.

-- Add email_verified if missing (run once). Default 1 keeps existing accounts able to log in.
-- ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

CREATE TABLE IF NOT EXISTS email_otps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  purpose VARCHAR(40) NOT NULL DEFAULT 'signup',
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_otps_email_purpose (email, purpose),
  INDEX idx_email_otps_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
