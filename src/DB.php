<?php
namespace AppZz\Http\RT;
use PDO;
use Envms\FluentPDO\Query AS FPDO;
use AppZz\Helpers\Arr;
use Exception;

class DB {

    protected static $_config;
    protected static $_instance;

    protected function __construct ()
    {
        $pdo = new PDO (DB::config ('db.dsn'), DB::config ('db.username'), DB::config ('db.password'));
        DB::$_instance = new FPDO ($pdo);
    }

    public static function disconnect ()
    {
        if (is_object(DB::$_instance)) {
            DB::$_instance->close();
        }
    }

    public static function config ($config = [], $default = NULL)
    {
        if ( ! empty ($config) AND is_array ($config)) {
            DB::$_config = $config;
        } else {
            return Arr::path (DB::$_config, $config, '.', $default);
        }
    }

    public static function instance ()
    {
        if ( ! is_object(DB::$_instance)) {
            new DB ();
        }

        return DB::$_instance;
    }

    public static function error ($message, $code = 0)
    {
        throw new Exception ($message, $code);
    }
}
