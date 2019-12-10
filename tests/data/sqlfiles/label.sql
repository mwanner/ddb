CREATE TEMPORARY TABLE tmp_table AS
SELECT 40 AS v0
UNION SELECT 41
UNION SELECT 42
UNION SELECT 43;

DELIMITER //

DROP PROCEDURE IF EXISTS proc //
CREATE PROCEDURE proc(OUT _oup INT(10) UNSIGNED)
main: BEGIN

  DECLARE done_ INT DEFAULT FALSE;
  DECLARE cur_ CURSOR FOR SELECT v0 FROM tmp_table;

  OPEN cur_;
  cur_loop: LOOP
    FETCH cur_ INTO _oup;
    IF done_ THEN
      LEAVE cur_loop;
    END IF;

    IF _oup >= 42 THEN
      LEAVE main; -- solution found
    END IF;
  END LOOP;
  CLOSE cur_;

END //

DELIMITER ;

CALL proc(@solution);
