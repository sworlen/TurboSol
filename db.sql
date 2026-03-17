-- TurboSol Faucet database schema for MySQL/MariaDB (phpMyAdmin import)

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_address VARCHAR(44) NOT NULL UNIQUE,
    faucetpay_id VARCHAR(100) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    referral_code VARCHAR(12) DEFAULT NULL UNIQUE,
    referred_by VARCHAR(12) DEFAULT NULL,
    last_claim DATETIME DEFAULT NULL,
    balance DECIMAL(18,9) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    ip VARCHAR(45) DEFAULT NULL,
    amount DECIMAL(18,9),
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_claims_user_id FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY DEFAULT 1,
    claim_interval_minutes INT DEFAULT 30,
    min_amount DECIMAL(18,9) DEFAULT 0.00005,
    max_amount DECIMAL(18,9) DEFAULT 0.0003,
    faucet_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performance indexes
CREATE INDEX idx_users_last_claim ON users(last_claim);
CREATE INDEX idx_users_created_at ON users(created_at);
CREATE INDEX idx_users_referral_code ON users(referral_code);
CREATE INDEX idx_users_referred_by ON users(referred_by);
CREATE INDEX idx_claims_user_id ON claims(user_id);
CREATE INDEX idx_claims_ip_created_at ON claims(ip, created_at);
CREATE INDEX idx_claims_status ON claims(status);
CREATE INDEX idx_claims_created_at ON claims(created_at);
CREATE INDEX idx_logs_action ON logs(action);
CREATE INDEX idx_logs_created_at ON logs(created_at);

-- Default settings row
INSERT IGNORE INTO settings (id) VALUES (1);

-- Instrukce:
-- 1. Vytvoř DB na InfinityFree.
-- 2. Importuj tento sql v phpMyAdmin.
-- 3. Uprav config.php s DB údaji.
