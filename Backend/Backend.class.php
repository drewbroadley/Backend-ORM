<?

/*
 * Class: Backend
 * 
 * Description:
 * Handles all of the page requests and details
 *  
 */

class Backend {

    protected static $connection;
    protected $schema = array();
    protected $query = null;
    public $debug = false;
    protected $cache = array();
    protected $rows = array();
    protected $row = array();
    protected $row_count = 0;
    protected $row_current = 0;

    function Backend($host, $name, $user, $pass) {
        $this->host = $host;
        $this->name = $name;
        $this->user = $user;
        $this->pass = $pass;

        self::$connection = $this->connect();
    }

    function connect() {
        self::$connection = new PDO(
                        "mysql:host=" . $this->host . ";dbname=" . $this->name,
                        $this->user,
                        $this->pass,
                        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
        );

        return self::$connection;
    }

    function query($sql) {
        if (func_num_args() > 1) {
            $args = array();
            $args_func = func_get_args();
            array_shift($args_func);

            foreach ($args_func as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $arg_child) {
                        $args[] = addslashes($arg_child);
                    }
                } else {
                    $args[] = addslashes($arg);
                }
            }

            $sql = @vsprintf($sql, $args);
        }

        //print $sql;

        $query = self::$connection->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL));
        if ($query->errorCode() > 0) {
            $error = $this->query->errorInfo();
            Log::error($this->query->errorCode(), $error[2], $sql);
        } else {
            $query->execute();
            $this->query = $query;

            return $query;
        }
    }

    function get($query = null) {
        $query = (!isset($query)) ? $this->query : $query;

        $this->row_current = 0;
        $this->row_count = 0;
        $this->rows = array();

        while ($row = $query->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT)) {
            $this->rows[($this->row_count + 1)] = $row;
            $this->row_count++;
        }

        return $this->rows;
    }

    function quote($string) {
        return self::$connection->quote($string);
    }

    function count() {
        return (int) $this->row_count;
    }

    function num_rows($query = null) {
        $query = (!isset($query)) ? $this->$query : $query;

        return $query->rowCount();
    }

    function insert_id() {
        return self::$connection->lastInsertId();
    }

}