CREATE TABLE admins (
  admin_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE employees (
  employee_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(50) UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  department VARCHAR(120),
  position VARCHAR(120),
  status ENUM('active','inactive','suspended','resigned') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_emp_status (status),
  INDEX idx_emp_dept (department)
) ENGINE=InnoDB;

CREATE TABLE nfc_cards (
  card_uid VARCHAR(32) PRIMARY KEY,
  employee_id BIGINT NOT NULL,
  status ENUM('active','lost','blocked','expired') NOT NULL DEFAULT 'active',
  issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP NULL,
  note VARCHAR(255),
  INDEX idx_card_emp (employee_id),
  INDEX idx_card_status (status),
  CONSTRAINT fk_card_emp FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE doors (
  door_id VARCHAR(50) PRIMARY KEY,
  door_name VARCHAR(120) NOT NULL,
  location_path VARCHAR(200),
  doors_token_hash VARCHAR(255) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_seen_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_doors_token_hash (doors_token_hash),
  INDEX idx_doors_status (status),
  INDEX idx_doors_last_seen (last_seen_at)
) ENGINE=InnoDB;

CREATE TABLE acl_permissions (
  card_uid VARCHAR(32) NOT NULL,
  door_id VARCHAR(50) NOT NULL,
  allow TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (card_uid, door_id),
  INDEX idx_perm_door (door_id),
  CONSTRAINT fk_perm_card FOREIGN KEY (card_uid) REFERENCES nfc_cards(card_uid)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_perm_door FOREIGN KEY (door_id) REFERENCES doors(door_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE access_logs (
  log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts_server TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ts_client BIGINT NULL,
  door_id VARCHAR(50) NOT NULL,
  card_uid VARCHAR(32) NOT NULL,
  result ENUM('ALLOW','DENY') NOT NULL,
  reason VARCHAR(50) NOT NULL,
  employee_id BIGINT NULL,
  ip_addr VARCHAR(45) NULL,
  INDEX idx_logs_time (ts_server),
  INDEX idx_logs_door_time (door_id, ts_server),
  INDEX idx_logs_uid_time (card_uid, ts_server)
) ENGINE=InnoDB;
