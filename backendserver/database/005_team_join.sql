-- Additive referral joining fields; preserve existing accounts and trees.
ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(40) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS lo_users_username ON users (username);
ALTER TABLE user_profiles ADD COLUMN IF NOT EXISTS district VARCHAR(60) NULL;
ALTER TABLE user_profiles ADD COLUMN IF NOT EXISTS post_code VARCHAR(12) NULL;
CREATE TABLE IF NOT EXISTS lo_placement_lock (
  id TINYINT PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO lo_placement_lock (id) VALUES (1);