-- GRANTED — Full schema (10 tables, Phases 1-5). For a FRESH install only.
-- Already have the Phase 1-3 database? Use phase4-5_migration.sql instead.
CREATE DATABASE IF NOT EXISTS granted_db;
USE granted_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'scholar') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE scholarship_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholarship_type_id INT NOT NULL,
    rule_type ENUM('GPA', 'UNIT_LOAD', 'DOCUMENT', 'DEADLINE') NOT NULL,
    operator ENUM('>=', '<=', '>', '<', '==') NOT NULL,
    threshold_value VARCHAR(50) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    due_date DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scholarship_type_id) REFERENCES scholarship_types(id)
);

CREATE TABLE scholars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    program VARCHAR(100),
    year_level TINYINT,
    scholarship_type_id INT NOT NULL,
    current_gpa DECIMAL(4,2) DEFAULT NULL,
    current_units INT DEFAULT NULL,
    current_status ENUM('PASS', 'FAIL', 'PENDING') DEFAULT 'PENDING',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (scholarship_type_id) REFERENCES scholarship_types(id)
);

CREATE TABLE grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    grade DECIMAL(4,2) NOT NULL,
    units TINYINT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    FOREIGN KEY (scholar_id) REFERENCES scholars(id)
);

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    filepath VARCHAR(255) NOT NULL,
    status ENUM('Pending Review', 'Verified', 'Rejected') DEFAULT 'Pending Review',
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    reviewed_by INT DEFAULT NULL,
    remarks TEXT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (scholar_id) REFERENCES scholars(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

CREATE TABLE evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    rule_id INT NOT NULL,
    result ENUM('PASS', 'FAIL', 'PENDING') NOT NULL,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scholar_id) REFERENCES scholars(id),
    FOREIGN KEY (rule_id) REFERENCES rules(id),
    UNIQUE KEY unique_eval (scholar_id, rule_id)
);

-- Phase 4-5 tables
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    type ENUM('STATUS_CHANGE', 'DEADLINE_APPROACHING') NOT NULL,
    channel ENUM('EMAIL', 'SMS') NOT NULL DEFAULT 'EMAIL',   -- SMS reserved for urgent cases only
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    old_status ENUM('PASS', 'FAIL', 'PENDING') DEFAULT NULL, -- filled for STATUS_CHANGE
    new_status ENUM('PASS', 'FAIL', 'PENDING') DEFAULT NULL, -- filled for STATUS_CHANGE
    rule_id INT DEFAULT NULL,                                -- filled for DEADLINE_APPROACHING (prevents duplicate reminders)
    send_status ENUM('QUEUED', 'SENT', 'FAILED') NOT NULL DEFAULT 'QUEUED',
    error_message VARCHAR(255) DEFAULT NULL,                 -- Gmail API error, if the send failed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    FOREIGN KEY (scholar_id) REFERENCES scholars(id),
    FOREIGN KEY (rule_id) REFERENCES rules(id),
    INDEX idx_notif_scholar (scholar_id, created_at),
    INDEX idx_notif_reminder (scholar_id, rule_id, type)
);

CREATE TABLE notification_status_events (
    scholar_id INT NOT NULL,
    old_status ENUM('PASS', 'FAIL', 'PENDING', 'INITIAL') NOT NULL,
    new_status ENUM('PASS', 'FAIL', 'PENDING') NOT NULL,
    notification_id INT DEFAULT NULL,
    PRIMARY KEY (scholar_id, old_status, new_status),
    FOREIGN KEY (scholar_id) REFERENCES scholars(id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id)
);

CREATE TABLE renewal_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    decision ENUM('RENEWED', 'NOT_RENEWED', 'ON_HOLD') NOT NULL,
    remarks TEXT,
    calculated_status_at_decision ENUM('PASS', 'FAIL', 'PENDING') DEFAULT NULL, -- snapshot, for the final report
    decided_by INT NOT NULL,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scholar_id) REFERENCES scholars(id),
    FOREIGN KEY (decided_by) REFERENCES users(id),
    UNIQUE KEY unique_decision (scholar_id, school_year, semester)
);

INSERT INTO users (email, password_hash, role) VALUES
('admin@granted.local', '$2y$10$p89dwUfRPAiAj1V/Pe1YXe0g9oZWIoL4u.vWovomBu5sT7wWN3/.O', 'admin');


INSERT INTO scholarship_types (name, description) VALUES
('Academic Scholarship', 'Merit-based scholarship for University of Luzon students');
