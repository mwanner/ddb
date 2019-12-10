DROP FUNCTION get_user(INT);
DROP VIEW active_users;

CREATE FUNCTION get_users (user_id INT) $$ yada yada $$;
CREATE VIEW active_users AS
  SELECT 'more yada yada'
  FROM users;
