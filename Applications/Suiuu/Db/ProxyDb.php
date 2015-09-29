<?php
/**
 * Created by PhpStorm.
 * User : xin.zhang
 * Date : 15/8/24
 * Time : 下午4:54
 * Email: zhangxinmailvip@foxmail.com
 */

namespace Applications\Suiuu\Db;


use GatewayWorker\Lib\DbConnection;

class ProxyDb {

    private $connection;

    public function __construct(DbConnection $db){
        $this->connection=$db;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}