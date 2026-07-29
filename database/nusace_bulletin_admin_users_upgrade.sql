USE `nusace_bulletin`;

ALTER TABLE `users`
  MODIFY `role` ENUM('dean', 'admin', 'program_chair', 'student_officer') NOT NULL;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) NULL AFTER `is_locked`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `totp_enabled_at` DATETIME NULL AFTER `totp_enabled`;

INSERT INTO `users` (
  `username`,
  `name`,
  `role`,
  `default_username`,
  `default_password_hash`,
  `password_hash`,
  `is_locked`
)
SELECT
  'admin.user',
  'User Administrator',
  'admin',
  'admin.user',
  '$2y$10$lucqMU3EhbYb3TATqJ82QOHNN9ojUPyiGnXAQiC1NtM63K/kgTjZu',
  '$2y$10$lucqMU3EhbYb3TATqJ82QOHNN9ojUPyiGnXAQiC1NtM63K/kgTjZu',
  0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `users`
  WHERE `username` = 'admin.user'
);
