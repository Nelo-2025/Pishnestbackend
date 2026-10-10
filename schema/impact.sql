-- Impact report tables for organiser session uploads and goals.
-- impact.php can also auto-create these on first use.

CREATE TABLE IF NOT EXISTS organiser_impact_goals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organiser_id INT NOT NULL,
  label TEXT NOT NULL,
  metric VARCHAR(128) NOT NULL,
  target_value DECIMAL(12, 2) NOT NULL,
  operator ENUM('>=', '<=', '=') NOT NULL DEFAULT '>=',
  deadline DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_impact_goals_organiser (organiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organiser_session_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organiser_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  row_count INT NOT NULL DEFAULT 0,
  detected_json JSON NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_impact_uploads_organiser (organiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organiser_impact_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organiser_id INT NOT NULL,
  upload_id INT NULL,
  goals_text TEXT NOT NULL,
  original_filename VARCHAR(255) NULL,
  row_count INT NOT NULL DEFAULT 0,
  report_json JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_impact_reports_organiser (organiser_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organiser_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organiser_id INT NOT NULL,
  upload_id INT NULL,
  session_date DATE NOT NULL,
  headcount INT NULL,
  attributes JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_impact_sessions_organiser_date (organiser_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organiser_field_map (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organiser_id INT NOT NULL,
  field_name VARCHAR(128) NOT NULL,
  field_type ENUM('boolean', 'date', 'numeric', 'categorical', 'text', 'ignore') NOT NULL,
  UNIQUE KEY uq_impact_field_map (organiser_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
