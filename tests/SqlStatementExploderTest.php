<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Exceptions\StatementExploderException;
use Datahouse\Libraries\Database\FileChunkIterator;
use Datahouse\Libraries\Database\SqlStatementExploder;

/**
 * Class SqlStatementExploderTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class SqlStatementExploderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @param string $path files to load
     * @return array of tuples (line_no, statement) found in the file
     */
    public function getStmtsFromFile($path)
    {
        $ity = new FileChunkIterator(dirname(__FILE__) . '/' . $path);
        $x = new SqlStatementExploder($ity);
        $result = [];
        foreach ($x as $line_no => $stmt) {
            $result[] = [$line_no, $stmt];
        }
        return $result;
    }

    public function testSimple()
    {
        self::assertEquals([
            [1, 'UPDATE cfg SET cfg_value = \'1\' WHERE cfg_id = 8'],
            [4, 'DELETE FROM foo WHERE bar = 8'],
            [6, "INSERT INTO test\n  (x, y)\nVALUES (\n  (1, 4),\n  (7, 8)\n)"]
        ], $this->getStmtsFromFile('data/sqlfiles/simple.sql'));
    }

    public function testSingleline()
    {
        self::assertEquals([
            [1, 'UPDATE cfg SET cfg_value = \'1\' WHERE cfg_id = 8'],
            [1, 'DELETE FROM foo WHERE bar = 8'],
            [1, 'INSERT INTO test (x, y) VALUES (7, 8)']
        ], $this->getStmtsFromFile('data/sqlfiles/singleline.sql'));
    }

    public function testMysqlDelimiter1()
    {
        self::assertEquals([
            [4, "CREATE PROCEDURE dorepeat(p1 INT)\n"
                . "BEGIN\n  SET @x = 0;\n  REPEAT SET @x = @x + 1; "
                . "UNTIL @x > p1 END REPEAT;\nEND\n"],
            [13, 'CALL dorepeat(1000)'],
            [15, 'SELECT @x']
        ], $this->getStmtsFromFile('data/sqlfiles/delimiter1.sql'));
    }

    public function testMysqlDelimiter2()
    {
        self::assertEquals([
            [2, 'SELECT true'],
            [4, 'SELECT false']
        ], $this->getStmtsFromFile('data/sqlfiles/delimiter2.sql'));
    }

    public function testLabels()
    {
        self::assertEquals([
            [1, "CREATE TEMPORARY TABLE tmp_table AS
SELECT 40 AS v0
UNION SELECT 41
UNION SELECT 42
UNION SELECT 43"],
            [9, 'DROP PROCEDURE IF EXISTS proc '],
            [10, "CREATE PROCEDURE proc(OUT _oup INT(10) UNSIGNED)
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

END "],
            [33, 'CALL proc(@solution)']
        ], $this->getStmtsFromFile('data/sqlfiles/label.sql'));
    }

    public function testMysqlCommentApostrophe0()
    {
        self::assertEquals([
            [1, "SELECT 1"],
            [2, "SELECT 2"],
        ], $this->getStmtsFromFile('data/sqlfiles/apostrophe0.sql'));
    }

    public function testMysqlCommentApostrophe1()
    {
        self::assertEquals([
            [3, "CREATE PROCEDURE a()
BEGIN
  SELECT 1; -- this is Tom's value
  SELECT 2; -- and this is another value
END "],
            [11, "SELECT 42"],
        ], $this->getStmtsFromFile('data/sqlfiles/apostrophe1.sql'));
    }

    public function testMysqlCommentedKeywords()
    {
        self::assertEquals([
            [2, "/*!50003 CREATE*/ /*!50017 DEFINER=`user`@`localhost`*/ "
                . "/*!50003 TRIGGER some_insert_before_trigger BEFORE INSERT "
                . "ON a_table\n  FOR EACH ROW BEGIN\n    SET NEW.name = "
                . "some_func(NEW.name);\n  END */"],
        ], $this->getStmtsFromFile('data/sqlfiles/comments.sql'));
    }

    public function singleStmtRoundtrip($sql)
    {
        $ity = new \ArrayIterator([$sql]);
        $res = iterator_to_array(new SqlStatementExploder($ity));
        self::assertEquals(1, count($res));
        self::assertEquals($res[1], $sql);
    }

    public function testDollarquote()
    {
        $this->singleStmtRoundtrip('SELECT $$x$$');
        // still only a single statement
        $this->singleStmtRoundtrip('SELECT $$ quoted ; semicolon $$');

        $this->singleStmtRoundtrip('SELECT column$with$dollar$signs');
        $this->singleStmtRoundtrip("SELECT \$tag\$Dianne's horse\$tag\$");
    }

    public function testDollarquoteMultiStatements()
    {
        self::assertEquals([
            [1, 'SELECT $tag$x$tag$'],
            [2, 'SELECT $tag$and$$some$$dollars$tag$'],
            [3, 'SELECT $$y$$']
        ], $this->getStmtsFromFile('data/sqlfiles/dollarquotes.sql'));
    }

    public function testMysqlComment()
    {
        $this->singleStmtRoundtrip('SELECT /* comment */ 1');
        // still only a single statement.
        $this->singleStmtRoundtrip('SELECT /* commented ; semicolon */ 1');
    }

    public function testStringEscape()
    {
        $this->singleStmtRoundtrip("SELECT ''");
        $this->singleStmtRoundtrip("SELECT E''");

        $this->singleStmtRoundtrip("SELECT '\\''");
        $this->singleStmtRoundtrip("SELECT '\''");

        $this->singleStmtRoundtrip("SELECT E'\\''");
        $this->singleStmtRoundtrip("SELECT E'\''");
    }

    public function testUnterminatedString()
    {
        $this->setExpectedException(StatementExploderException::class);
        $this->singleStmtRoundtrip("SELECT '");
    }

    public function testUnterminatedEscapedString()
    {
        $this->setExpectedException(StatementExploderException::class);
        $this->singleStmtRoundtrip("SELECT E'");
    }

    public function testUnterminatedDollarQuote()
    {
        $this->setExpectedException(StatementExploderException::class);
        $this->singleStmtRoundtrip('SELECT $tag$ something');
    }

    public function testUnterminatedCStyleComment()
    {
        $this->setExpectedException(StatementExploderException::class);
        $this->singleStmtRoundtrip('SELECT /* something');
    }

    public function testNonstandardDoubleQuoteStrings()
    {
        $this->singleStmtRoundtrip('SELECT ""');
        $this->singleStmtRoundtrip("SELECT \"'\"");
    }

    /**
     * Covers a real use case: #5666.
     *
     * @return void
     */
    public function testMysqlDelimiterAtEof()
    {
        self::assertEquals(
            [[3, "SELECT true"]],
            $this->getStmtsFromFile('data/sqlfiles/delimiter3.sql')
        );
    }

    /**
     * @return void
     */
    public function testPostgresFdw()
    {
        $commands = $this->getStmtsFromFile('data/sqlfiles/delimiter4.sql');
        $firstSixEach = array_map(function ($v) {
            return [$v[0], substr($v[1], 0, 6)];
        }, $commands);
        self::assertEquals([[1, 'CREATE']], $firstSixEach);
    }

}
