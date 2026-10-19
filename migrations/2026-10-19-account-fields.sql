-- =====================================================================
-- ProcrastiNot - account content migration: optional profile details for
-- the dedicated My Account page (course/program + year level).
--
-- Run ONCE against an existing tasktrack_db:
--   C:\xampp\mysql\bin\mysql -u root tasktrack_db < migrations\2026-10-19-account-fields.sql
--
-- Fresh installs do NOT need this file - the current database.sql already
-- contains these columns. Both columns are optional and non-destructive, so
-- no existing account data is changed. Re-running the file is safe on the
-- XAMPP MariaDB build (IF NOT EXISTS).
-- =====================================================================

USE tasktrack_db;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS course VARCHAR(120) DEFAULT NULL
    COMMENT 'Optional: course or programme the student is enrolled in',
  ADD COLUMN IF NOT EXISTS year_level VARCHAR(40) DEFAULT NULL
    COMMENT 'Optional: free-text year level, e.g. "Year 2" or "Grade 11"';
