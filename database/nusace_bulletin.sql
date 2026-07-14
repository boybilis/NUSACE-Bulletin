CREATE DATABASE IF NOT EXISTS `nusace_bulletin`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `nusace_bulletin`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notice_reactions`;
DROP TABLE IF EXISTS `feedback_pending`;
DROP TABLE IF EXISTS `feedback`;
DROP TABLE IF EXISTS `notice_tags`;
DROP TABLE IF EXISTS `notices`;
DROP TABLE IF EXISTS `user_boards`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `role` ENUM('dean', 'program_chair') NOT NULL,
  `default_username` VARCHAR(100) NOT NULL,
  `default_password_hash` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_boards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `board_id` VARCHAR(64) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_boards_user` (`user_id`),
  CONSTRAINT `fk_user_boards_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notices` (
  `id` VARCHAR(64) NOT NULL,
  `board_id` VARCHAR(64) NOT NULL,
  `category` VARCHAR(120) NOT NULL,
  `audience` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `notice_date` DATE NOT NULL,
  `visible_from` DATE NOT NULL,
  `visible_until` DATE NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) NOT NULL,
  `created_by_name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `attachment_path` VARCHAR(255) NULL,
  `attachment_name` VARCHAR(255) NULL,
  `attachment_mime` VARCHAR(100) NULL,
  `attachment_kind` VARCHAR(32) NULL,
  `attachment_size` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notices_board` (`board_id`),
  KEY `idx_notices_visibility` (`visible_from`, `visible_until`),
  KEY `idx_notices_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notice_tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notice_id` VARCHAR(64) NOT NULL,
  `tag` VARCHAR(120) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_notice_tags_notice` (`notice_id`),
  CONSTRAINT `fk_notice_tags_notice`
    FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback` (
  `id` VARCHAR(64) NOT NULL,
  `board_id` VARCHAR(64) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `message` TEXT NOT NULL,
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 1,
  `email` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_board_created` (`board_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_pending` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id` VARCHAR(64) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `message` TEXT NOT NULL,
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 1,
  `otp_hash` VARCHAR(255) NOT NULL,
  `requested_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_pending_board_email` (`board_id`, `email`),
  KEY `idx_feedback_pending_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notice_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notice_id` VARCHAR(64) NOT NULL,
  `reaction_type` ENUM('like', 'heart') NOT NULL,
  `client_id` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notice_reaction` (`notice_id`, `reaction_type`, `client_id`),
  KEY `idx_notice_reactions_notice` (`notice_id`),
  CONSTRAINT `fk_notice_reactions_notice`
    FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `name`, `role`, `default_username`, `default_password_hash`, `password_hash`) VALUES
  (1, 'dean', 'SACE Dean', 'dean', 'dean', '$2y$10$N4drRGJT7fJg34Lnfv7VEe1Ql5xlkIh2lY0.3VsYOcHNvhEOs8f/y', '$2y$10$N4drRGJT7fJg34Lnfv7VEe1Ql5xlkIh2lY0.3VsYOcHNvhEOs8f/y'),
  (2, 'chair.architecture', 'Architecture Program Chair', 'program_chair', 'chair.architecture', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy'),
  (3, 'chair.computer-science', 'BS Computer Science Program Chair', 'program_chair', 'chair.computer-science', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy'),
  (4, 'chair.information-technology', 'BS Information Technology Program Chair', 'program_chair', 'chair.information-technology', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy'),
  (5, 'chair.engineering', 'Engineering Program Chair', 'program_chair', 'chair.engineering', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy'),
  (6, 'chair.mma', 'Multimedia Arts Program Chair', 'program_chair', 'chair.mma', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy'),
  (7, 'chair.cpe', 'Computer Engineering Program Chair', 'program_chair', 'chair.cpe', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy', '$2y$10$oChZuMBnkJhmuEXdRQyFW.DuRJzLDWUo5ywNLfI.sDaz1GgACUWHy');

INSERT INTO `user_boards` (`user_id`, `board_id`, `sort_order`) VALUES
  (1, 'sace', 0),
  (1, 'architecture', 1),
  (1, 'computer-science', 2),
  (1, 'information-technology', 3),
  (1, 'engineering', 4),
  (1, 'mma', 5),
  (1, 'cpe', 6),
  (2, 'architecture', 0),
  (3, 'computer-science', 0),
  (4, 'information-technology', 0),
  (5, 'engineering', 0),
  (6, 'mma', 0),
  (7, 'cpe', 0);

INSERT INTO `notices` (
  `id`, `board_id`, `category`, `audience`, `title`, `notice_date`, `visible_from`, `visible_until`,
  `body_text`, `pinned`, `created_by`, `created_by_name`, `created_at`, `updated_at`,
  `attachment_path`, `attachment_name`, `attachment_mime`, `attachment_kind`, `attachment_size`
) VALUES
  ('notice_6a1e7923ebbd94.44682330', 'information-technology', 'Examination Schedule', 'Students and Faculties', '3rd Term Ay 25-26 Finals Schedule', '2026-06-02', '2026-06-01', '2026-06-06', 'Final Exam: June 1-6\r\nGrade Consultation: June 8-9\r\nGrade Encoding: June 10', 1, 'chair.information-technology', 'BS Information Technology Program Chair', '2026-06-02 06:33:07', '2026-06-02 06:33:07', NULL, NULL, NULL, NULL, NULL),
  ('notice_6a1e93e4349e57.80594360', 'information-technology', 'Enrollment', 'Students', 'Students with Enrollment Problem', '2026-06-02', '2026-06-02', '2026-07-31', 'All irregular students/students with subject registration problems, please visit your program chair before paying your downpayment / tuition fee for 1st Term AY26-27', 1, 'chair.information-technology', 'BS Information Technology Program Chair', '2026-06-02 08:27:16', '2026-06-02 08:27:16', NULL, NULL, NULL, NULL, NULL),
  ('notice_6a1e8e6ac1c029.23918652', 'sace', 'Faculty Advisory', 'Faculty', 'Last Day of Grade Encoding 3rd Term AY25-26', '2026-06-02', '2026-06-01', '2026-06-10', 'Last Day of Grade Encoding 3rd Term AY25-26:\r\nJune 10, 2026', 0, 'dean', 'SACE Dean', '2026-06-02 08:03:54', '2026-06-02 08:03:54', NULL, NULL, NULL, NULL, NULL);

INSERT INTO `notice_tags` (`notice_id`, `tag`, `sort_order`) VALUES
  ('notice_6a1e7923ebbd94.44682330', 'Examination', 0),
  ('notice_6a1e93e4349e57.80594360', 'irregular students', 0),
  ('notice_6a1e8e6ac1c029.23918652', 'Advisory', 0);

INSERT INTO `notice_reactions` (`notice_id`, `reaction_type`, `client_id`) VALUES
  ('notice_6a1e7923ebbd94.44682330', 'heart', 'client-1780386262792-wb74dt728y'),
  ('notice_6a1e7923ebbd94.44682330', 'heart', 'client-1780386315593-tsjc87dmae'),
  ('notice_6a1e7923ebbd94.44682330', 'like', 'client-1780386262792-wb74dt728y'),
  ('notice_6a1e8e6ac1c029.23918652', 'like', 'client-1780386262792-wb74dt728y'),
  ('notice_6a1e8e6ac1c029.23918652', 'like', 'client-1780386672997-b8ksvmeggp');

SET FOREIGN_KEY_CHECKS = 1;
