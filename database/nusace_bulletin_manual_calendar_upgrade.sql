USE `nusace_bulletin`;

CREATE TABLE IF NOT EXISTS `manual_calendar_events` (
  `id` VARCHAR(64) NOT NULL,
  `board_id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `event_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_by_name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_manual_calendar_month` (`event_date`, `start_time`),
  KEY `idx_manual_calendar_board` (`board_id`),
  KEY `idx_manual_calendar_owner` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
