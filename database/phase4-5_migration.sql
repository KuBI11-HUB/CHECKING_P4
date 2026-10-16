-- GRANTED — Migration for Phase 4 (notifications/deadlines) and Phase 5 (renewal decisions)
-- Adds 2 tables (notifications, renewal_decisions)
-- and 1 column (rules.due_date).
-- Run this ONCE on an existing granted_db that already has the Phase 1-3 tables.
-- It does not touch or delete any existing data.
-- Written for MariaDB (XAMPP). On MySQL 8, remove "IF NOT EXISTS" from the ALTER TABLE line.

USE granted_db;

-- ---------------------------------------------------------------
-- 1. notifications  (Phase 4)
--    One row per email (or future SMS) the system sends or tries to send.
--    This is also what the scholar's "Notification history" page reads.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
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

-- ---------------------------------------------------------------
-- 2. renewal_decisions  (Phase 5)
--    The office's ACTUAL decision. Completely separate from scholars.current_status,
--    which only the rule engine may set. One decision per scholar per term.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS renewal_decisions (
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

-- ---------------------------------------------------------------
-- 3. Deadlines  (Phase 4)
--    A submission deadline belongs to a rule, so the scholar's checklist can show
--    each outstanding document together with its due date.
-- ---------------------------------------------------------------

-- Submission deadline on a rule (NULL = no deadline)
ALTER TABLE rules ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER semester;
