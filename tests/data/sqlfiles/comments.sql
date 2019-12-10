DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`user`@`localhost`*/ /*!50003 TRIGGER some_insert_before_trigger BEFORE INSERT ON a_table
  FOR EACH ROW BEGIN
    SET NEW.name = some_func(NEW.name);
  END */;;
DELIMITER ;
