-- =====================================================================
-- ProcrastiNot - Student Task Management System
-- Database setup file: tasktrack_db
-- Import this file through phpMyAdmin (see README.md).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS tasktrack_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tasktrack_db;

-- ---------------------------------------------------------------------
-- users: one row per student account
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(100) NOT NULL,
  email               VARCHAR(255) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  greeting            VARCHAR(150) DEFAULT NULL,
  course              VARCHAR(120) DEFAULT NULL
                        COMMENT 'Optional: course or programme the student is in',
  year_level          VARCHAR(40) DEFAULT NULL
                        COMMENT 'Optional: free-text year level, e.g. "Year 2"',
  daily_study_minutes INT UNSIGNED DEFAULT NULL
                        COMMENT 'Self-reported study time per day, used by the priority engine',
  weekly_goal         SMALLINT UNSIGNED NOT NULL DEFAULT 5
                        COMMENT 'Target number of tasks to complete each week',
  xp_enabled          TINYINT(1) NOT NULL DEFAULT 1
                        COMMENT 'Students may switch gamification off entirely',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- categories: subjects owned by one student
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_user_name (user_id, name),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- tasks: one row per student task; every task belongs to its owner
-- Overdue is NEVER stored - it is derived:
--   due_date < CURDATE() AND status <> 'completed'
-- ---------------------------------------------------------------------
CREATE TABLE tasks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  title       VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  notes       TEXT DEFAULT NULL,
  priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  status      ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  due_date    DATE DEFAULT NULL,
  my_day      TINYINT(1) NOT NULL DEFAULT 0,
  estimated_minutes INT UNSIGNED DEFAULT NULL
                COMMENT 'Estimated minutes of work, feeds the priority engine',
  original_due_date DATE DEFAULT NULL
                COMMENT 'First deadline set; preserved when a deadline is rescheduled',
  completed_at DATETIME DEFAULT NULL
                COMMENT 'When the task last moved to completed (used for on-time rate)',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_user_due (user_id, due_date),
  KEY idx_tasks_user_status (user_id, status),
  KEY idx_tasks_user_completed (user_id, completed_at),
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- task_subtasks: optional breakdown of a task into small steps.
-- ---------------------------------------------------------------------
CREATE TABLE task_subtasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  title        VARCHAR(200) NOT NULL,
  position     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_done      TINYINT(1) NOT NULL DEFAULT 0,
  completed_at DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subtasks_task (task_id),
  KEY idx_subtasks_user (user_id),
  CONSTRAINT fk_subtasks_task FOREIGN KEY (task_id)
    REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_subtasks_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- focus_sessions: a "Just 5 Minutes" work session on one task.
-- ---------------------------------------------------------------------
CREATE TABLE focus_sessions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id         INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  planned_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  actual_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  note            VARCHAR(500) DEFAULT NULL,
  started_at      DATETIME NOT NULL,
  ended_at        DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_focus_user (user_id, started_at),
  KEY idx_focus_task (task_id),
  CONSTRAINT fk_focus_task FOREIGN KEY (task_id)
    REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_focus_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- xp_transactions: append-only points ledger. uq_xp_event guarantees a
-- single completion can never be rewarded twice (anti-toggling rule).
-- ---------------------------------------------------------------------
CREATE TABLE xp_transactions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  task_id    INT UNSIGNED DEFAULT NULL,
  source     VARCHAR(40) NOT NULL,
  points     INT NOT NULL,
  event_key  VARCHAR(80) NOT NULL,
  note       VARCHAR(200) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_xp_event (user_id, event_key),
  KEY idx_xp_user (user_id, created_at),
  CONSTRAINT fk_xp_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_xp_task FOREIGN KEY (task_id)
    REFERENCES tasks (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- user_achievements: badges earned (one row per badge per student).
-- ---------------------------------------------------------------------
CREATE TABLE user_achievements (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id   INT UNSIGNED NOT NULL,
  badge_key VARCHAR(40) NOT NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_badge (user_id, badge_key),
  CONSTRAINT fk_badges_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- task_deadline_history: audit trail for Comeback Mode reschedules.
-- The original deadline is never lost.
-- ---------------------------------------------------------------------
CREATE TABLE task_deadline_history (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  old_due_date DATE DEFAULT NULL,
  new_due_date DATE DEFAULT NULL,
  reason       VARCHAR(200) DEFAULT NULL,
  changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ddh_task (task_id),
  KEY idx_ddh_user (user_id),
  CONSTRAINT fk_ddh_task FOREIGN KEY (task_id)
    REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_ddh_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- OPTIONAL SAMPLE DATA - clearly labeled, safe to delete.
-- Demo login: student@example.com / password123
-- =====================================================================
INSERT INTO users (id, name, email, password_hash, greeting, course, year_level) VALUES
(1, 'Demo Student', 'student@example.com',
 '$2y$12$PMyoyvmCwhfdhV1a57jE7es1.n0Yd52Z.ezTck6vXl0GkjMFqUTte',
 'Ready to focus, Demo?', 'BS Information Technology', 'Year 2');

INSERT INTO categories (id, user_id, name) VALUES
(1, 1, 'General'),
(2, 1, 'Mathematics'),
(3, 1, 'Science');

INSERT INTO tasks (user_id, category_id, title, description, notes, priority, status, due_date, my_day, estimated_minutes, original_due_date, completed_at, created_at, updated_at) VALUES
(1, 2, 'Finish algebra homework', 'Complete exercises 1-15 on quadratic equations.', 'Ask teacher about question 9', 'high', 'pending', CURDATE(), 1, 90, CURDATE(), NULL, NOW(), NOW()),
(1, 3, 'Read chapter 4', 'Photosynthesis chapter summary and key terms.', NULL, 'medium', 'in_progress', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 0, 60, DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL, NOW(), NOW()),
(1, 1, 'Buy lab notebook', NULL, NULL, 'low', 'pending', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 0, 20, DATE_ADD(CURDATE(), INTERVAL 5 DAY), NULL, NOW(), NOW()),
(1, 2, 'Overdue worksheet', 'Trigonometry practice sheet.', NULL, 'high', 'pending', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 0, 45, DATE_SUB(CURDATE(), INTERVAL 3 DAY), NULL, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1, 3, 'Write lab report', 'Experiment 2 results and conclusion.', 'Submit as PDF', 'medium', 'completed', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 0, 120, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY));
