-- ---------------------------------------------------------
-- Guest checkout: visitors can buy tickets without logging in.
-- A quiet customer account is created for their email with is_guest = 1
-- and no usable password. It becomes a normal account (is_guest = 0)
-- once they set a password via "Forgot password" or sign in with Google.
-- Run once in phpMyAdmin on the live database.
-- ---------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_guest` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;
