-- Optional guest checkout: families can book with name + email only.
-- user_id stays populated when the booker is logged in or creates an account.

ALTER TABLE orders
  MODIFY user_id INT NULL,
  ADD COLUMN guest_name VARCHAR(120) NULL AFTER user_id,
  ADD COLUMN guest_email VARCHAR(255) NULL AFTER guest_name;

ALTER TABLE orders
  ADD INDEX idx_orders_guest_email (guest_email);
