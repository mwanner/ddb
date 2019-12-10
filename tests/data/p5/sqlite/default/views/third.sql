CREATE VIEW third AS
  SELECT
    f.id,
    s.other_field
  FROM first f
  LEFT JOIN second s
    ON f.id = s.first_id;
