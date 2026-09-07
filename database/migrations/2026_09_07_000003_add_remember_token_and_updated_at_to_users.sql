-- up
ALTER TABLE users
    ADD COLUMN remember_token VARCHAR(100) NULL AFTER password,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at;

-- down
ALTER TABLE users
    DROP COLUMN remember_token,
    DROP COLUMN updated_at;
