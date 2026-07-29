USE `nusace_bulletin`;

ALTER TABLE `users`
  MODIFY `role` ENUM('dean', 'admin', 'program_chair', 'student_officer') NOT NULL;
