DELIMITER //

CREATE PROCEDURE a()
BEGIN
  SELECT 1; -- this is Tom's value
  SELECT 2; -- and this is another value
END //

DELIMITER ;

SELECT 42;
