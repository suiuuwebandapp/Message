<?php
/**
 * Created by PhpStorm.
 * User : xin.zhang
 * Date : 15/8/24
 * Time : 下午4:35
 * Email: zhangxinmailvip@foxmail.com
 */

namespace Applications\Suiuu\Db;

use GatewayWorker\Lib\DbConnection;

class BaseDb {


    private $connection;


    public function getConnection(){
        $host='localhost';
        $port='3306';
        $user='root';
        $password='suiuu';
        $dbName='suiuu';
        $this->connection=new DbConnection($host,$port,$user,$password,$dbName);
        return $this->connection;
    }

    public function closeLink(){
        $this->connection->closeConnection();
    }

    public function getLastInsertId(){
        $this->connection->lastInsertId();
    }


    /**
     * 数组转换成自定义对象
     * @param $array
     * @param $className
     * @return mixed
     * @throws \Exception
     */
    public static function arrayCastObject($array,$className)
    {
        if($array==null||$array === false){
            return null;
        };

        if(class_exists($className)) {
            $newClass=new $className;
            foreach($newClass as $prop =>$val){
                $val=array_key_exists($prop,$array)?$array[$prop]:null;
                $newClass->$prop=$val;
            }
            return $newClass;
        }else{
            throw new \Exception('Undefined ClassName Exception');
        }
    }

}