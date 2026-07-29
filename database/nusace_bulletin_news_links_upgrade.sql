USE `nusace_bulletin`;

CREATE TABLE IF NOT EXISTS `news_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `summary` TEXT NOT NULL,
  `facebook_url` VARCHAR(1000) NOT NULL,
  `image_url` VARCHAR(1000) NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_news_links_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `news_links` (
  `title`, `summary`, `facebook_url`, `image_url`, `created_by`, `created_at`, `updated_at`
)
SELECT
  'NU Lipa SACE Facebook Update',
  'Read the latest news and community update shared through the official Facebook post.',
  'https://web.facebook.com/share/1DcLmfCW5W/',
  NULL,
  'admin.user',
  NOW(),
  NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `news_links`
  WHERE `facebook_url` = 'https://web.facebook.com/share/1DcLmfCW5W/'
);
