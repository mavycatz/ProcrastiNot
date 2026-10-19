-- =====================================================================
-- ProcrastiNot - upgrade migration: Smart Priority, Just 5 Minutes,
-- Comeback Mode, Anti-Cramming XP.
--
-- Run this ONCE against an existing tasktrack_db that was created from an
-- older copy of database.sql. Fresh installs do NOT need this file - the
-- current database.sql already contains everything below.
--
--   C:\xampp\mysql\bin\mysql -u root tasktrack_db < migrations\2026-10-19-smart-upgrade.sql
--
-- Every statement is additive and non-destructive: no existing column,
-- row, or relationship is dropped or renamed. Re-running the file is safe
-- because the ALTERs use IF NOT EXISTS and the tables use CREATE TABLE IF
-- NOT EXISTS (MariaDB 10.2+ / MySQL 8 supported by current XAMPP).
-- =====================================================================

USE tasktrack_db;

-- ---------------------------------------------------------------------
-- users: available study time, weekly goal, optional gamification.
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS daily_study_minutes INT UNSIGNED DEFAULT NULL
    COMMENT 'Self-reported study time per day, used by the priority engine',
  ADD COLUMN IF NOT EXISTS weekly_goal SMALLINT UNSIGNED NOT NULL DEFAULT 5
    COMMENT 'Target number of tasks to complete each week',
  ADD COLUMN IF NOT EXISTS xp_enabled TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Students may switch gamification off entirely';

-- ---------------------------------------------------------------------
-- tasks: estimated effort, preserved original deadline, completion stamp.
-- ---------------------------------------------------------------------
ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS estimated_minutes INT UNSIGNED DEFAULT NULL
    COMMENT 'Estimated minutes of work, feeds the priority engine',
  ADD COLUMN IF NOT EXISTS original_due_date DATE DEFAULT NULL
    COMMENT 'First deadline set; preserved when a deadline is rescheduled',
  ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL
    COMMENT 'When the task last moved to completed (used for on-time rate)';

-- Backfill the preserved original deadline for existing rows.
UPDATE tasks SET original_due_date = due_date
  WHERE original_due_date IS NULL AND due_date IS NOT NULL;

-- Backfill a completion stamp for rows already completed.
UPDATE tasks SET completed_at = updated_at
  WHERE completed_at IS NULL AND status = 'completed';

-- Index for on-time / productive-day reporting.
ALTER TABLE tasks
  ADD INDEX IF NOT EXISTS idx_tasks_user_completed (user_id, completed_at);

-- ---------------------------------------------------------------------
-- task_subtasks: optional breakdown of a task into small steps.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_subtasks (
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
CREATE TABLE IF NOT EXISTS focus_sessions (
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
CREATE TABLE IF NOT EXISTS xp_transactions (
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
CREATE TABLE IF NOT EXISTS user_achievements (
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
CREATE TABLE IF NOT EXISTS task_deadline_history (
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
