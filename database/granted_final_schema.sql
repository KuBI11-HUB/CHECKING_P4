-- GRANTED final schema reference for a fresh, empty granted_dev database.
-- This is NOT a migration for a populated database. It intentionally does
-- not CREATE DATABASE or USE a database. Select granted_dev before running.
-- Do not run this against the demo database or an existing populated schema.
--
-- MySQL/MariaDB DDL auto-commits; if a CREATE fails partway through, recreate
-- the empty development database from a backup rather than continuing from a
-- partial install.
--
-- GWA records are usable only when their source document is currently
-- Verified, belongs to the same scholar and term, and is identifiable as a
-- Certificate of Grades. The application must repeat these checks every time
-- it evaluates a GWA rule because document status can change later.
--
-- This final schema uses official GWA instead of calculated GPA. It does not
-- convert or copy existing GPA rules or calculated GPA values; those require
-- separate handling before migrating a populated database.

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'scholar') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_programs_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE scholarship_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scholarship_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO scholarship_types (name, description) VALUES
    ('CHED', 'Commission on Higher Education scholarship'),
    ('DOST', 'Department of Science and Technology scholarship'),
    ('Mayor''s Scholar', 'Mayor-sponsored scholarship'),
    ('TES', 'Tertiary Education Subsidy');

CREATE TABLE requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_requirements_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO requirements (name, description, is_recurring) VALUES
    ('PSA', 'PSA identity document', 0),
    ('Proof of Residency', 'Proof of current residency', 0),
    ('Certificate of Grades', 'Official term grades and GWA record source', 1);

CREATE TABLE scholars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fname VARCHAR(100) NOT NULL,
    mname VARCHAR(100) NULL,
    lname VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    program_id INT NULL,
    year_level TINYINT NULL,
    current_gwa DECIMAL(4,2) NULL,
    current_units INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scholars_user (user_id),
    INDEX idx_scholars_program (program_id),
    CONSTRAINT fk_scholars_users
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_scholars_programs
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One reusable row per scholar/type. A re-award reactivates this row; it does
-- not create a second row or manually set status. Only the evaluation engine
-- writes status. Lifecycle fields are separate from calculated status.
CREATE TABLE scholarship_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    scholarship_type_id INT NOT NULL,
    status ENUM('PASS', 'FAIL', 'PENDING') NOT NULL DEFAULT 'PENDING',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    start_school_year VARCHAR(9) NULL,
    start_semester ENUM('1st', '2nd', 'Summer') NULL,
    ended_at TIMESTAMP NULL,
    last_activated_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assignment_scholar_type (scholar_id, scholarship_type_id),
    INDEX idx_assignments_scholar_active (scholar_id, is_active),
    INDEX idx_assignments_type (scholarship_type_id),
    CONSTRAINT fk_scholarship_assignments_scholars
        FOREIGN KEY (scholar_id) REFERENCES scholars(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_scholarship_assignments_scholarship_types
        FOREIGN KEY (scholarship_type_id) REFERENCES scholarship_types(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholarship_type_id INT NOT NULL,
    rule_type ENUM('GWA', 'UNIT_LOAD', 'DOCUMENT', 'DEADLINE') NOT NULL,
    requirement_id INT NULL,
    operator ENUM('>=', '<=', '>', '<', '==') NOT NULL,
    threshold_value VARCHAR(100) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    due_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rules_type_term (scholarship_type_id, school_year, semester, is_active),
    INDEX idx_rules_requirement (requirement_id),
    CONSTRAINT fk_rules_scholarship_types
        FOREIGN KEY (scholarship_type_id) REFERENCES scholarship_types(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_rules_requirements
        FOREIGN KEY (requirement_id) REFERENCES requirements(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    requirement_id INT NULL,
    filepath VARCHAR(255) NOT NULL,
    submission_token CHAR(64) NOT NULL,
    status ENUM('Pending Review', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending Review',
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    reviewed_by INT NULL,
    remarks TEXT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    UNIQUE KEY uq_documents_submission_token (submission_token),
    INDEX idx_documents_scholar_requirement_term
        (scholar_id, requirement_id, school_year, semester, status),
    CONSTRAINT fk_documents_scholars
        FOREIGN KEY (scholar_id) REFERENCES scholars(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_documents_reviewed_by_users
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_documents_requirements
        FOREIGN KEY (requirement_id) REFERENCES requirements(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gwa_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    gwa DECIMAL(4,2) NOT NULL,
    total_units INT NOT NULL,
    source_document_id INT NOT NULL,
    encoded_by INT NOT NULL,
    encoded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gwa_records_scholar_term (scholar_id, school_year, semester),
    CONSTRAINT fk_gwa_records_scholars
        FOREIGN KEY (scholar_id) REFERENCES scholars(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gwa_records_documents
        FOREIGN KEY (source_document_id) REFERENCES documents(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_gwa_records_encoded_by_users
        FOREIGN KEY (encoded_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The schema enforces that the source document exists. The PHP GWA-entry
-- handler and evaluation engine must additionally verify that it is currently
-- Verified, belongs to the same scholar, matches this school year/semester,
-- and is the Certificate of Grades (catalogue match when requirement_id is
-- set; otherwise exact document_type match). The evaluator must repeat these
-- checks every time because document status can change after GWA entry.

-- Evaluations are overwritten on reevaluation; they are not term history.
-- Because each rule belongs to one scholarship type, (scholar_id, rule_id)
-- identifies the current result for that scholar/type rule.
CREATE TABLE evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    rule_id INT NOT NULL,
    result ENUM('PASS', 'FAIL', 'PENDING') NOT NULL,
    evaluated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evaluations_scholar_rule (scholar_id, rule_id),
    CONSTRAINT fk_evaluations_scholars
        FOREIGN KEY (scholar_id) REFERENCES scholars(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_evaluations_rules
        FOREIGN KEY (rule_id) REFERENCES rules(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE renewal_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st', '2nd', 'Summer') NOT NULL,
    decision ENUM('RENEWED', 'NOT_RENEWED', 'ON_HOLD') NOT NULL,
    remarks TEXT NULL,
    calculated_status_at_decision ENUM('PASS', 'FAIL', 'PENDING') NULL,
    decided_by INT NOT NULL,
    decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_renewal_decisions_assignment_term
        (assignment_id, school_year, semester),
    CONSTRAINT fk_renewal_decisions_assignments
        FOREIGN KEY (assignment_id) REFERENCES scholarship_assignments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_renewal_decisions_decided_by_users
        FOREIGN KEY (decided_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    assignment_id INT NULL,
    type ENUM('STATUS_CHANGE', 'DEADLINE_APPROACHING') NOT NULL,
    channel ENUM('EMAIL') NOT NULL DEFAULT 'EMAIL',
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    old_status ENUM('PASS', 'FAIL', 'PENDING') NULL,
    new_status ENUM('PASS', 'FAIL', 'PENDING') NULL,
    rule_id INT NULL,
    send_status ENUM('QUEUED', 'SENT', 'FAILED') NOT NULL DEFAULT 'QUEUED',
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    INDEX idx_notifications_scholar_created (scholar_id, created_at),
    INDEX idx_notifications_reminder (scholar_id, rule_id, type),
    CONSTRAINT fk_notifications_scholars
        FOREIGN KEY (scholar_id) REFERENCES scholars(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_notifications_assignments
        FOREIGN KEY (assignment_id) REFERENCES scholarship_assignments(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_notifications_rules
        FOREIGN KEY (rule_id) REFERENCES rules(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(255) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_ip_time (ip, attempted_at),
    INDEX idx_login_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
