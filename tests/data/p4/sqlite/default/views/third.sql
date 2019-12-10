CREATE VIEW third AS
  SELECT f.id
  FROM first f
  LEFT JOIN second s
    ON f.id = s.first_id;
