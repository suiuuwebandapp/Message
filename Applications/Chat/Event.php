<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose
 */

use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Store;

class Event
{

    /**
     * 有消息时
     * @param int $client_id
     * @param string $message
     */
    public static function onMessage($client_id, $message)
    {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";

        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }

        // 根据类型执行不同的业务
        switch($message_data['type']) {
            // 客户端回应服务端的心跳
            case 'pong':
                //判断是否有新的未读消息
                $all_clients=self::getClientListFromRoom($_SESSION['room_id']);
                var_dump($all_clients);
                //如果有 获取用户未读消息列表 并且刷新session
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
            case 're_login':
                // 判断是否有房间号
                if (!isset($message_data['user_key'])) {
                    throw new \Exception("\$message_data['user_key'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }

                // 把房间号昵称放到session中
                $userKey = $message_data['user_key'];
                $userInfo = self::findUserInfoByUserKey($userKey);
                if (empty($userInfo)) {
                    throw new \Exception("\$message_data['user_info'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                //获取用户未读消息数存入session

                // 把房间号昵称放到session中
                $room_id = 1;
                $userSign = htmlspecialchars($userInfo['userSign']);
                $_SESSION['room_id'] = $room_id;
                $_SESSION['userSign'] = $userSign;
                $_SESSION['user_info'] = $userInfo;

//                $all_clients = self::getClientListFromRoom($room_id);
//                if (!empty($all_clients)) {
//                    $receiveClientId = array_search($userSign, $all_clients);
//                    if ($receiveClientId !== false) {
//                        self::delClientFromRoom($room_id, $receiveClientId);
//                    }
//                }
                self::addClientToRoom($room_id, $client_id, $userSign);


                // 存储到当前房间的客户端列表
                return;

            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            //ws.send(JSON.stringify({"type": "say","to_client_id": "a4c1406ff4cc382389f19bf6ec3e55c1","content": "哈哈"}));

            case 'say':
                // 非法请求
                if (!isset($_SESSION['user_info'])) {
                    throw new \Exception("\$user_info not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['content'])) {
                    throw new \Exception("\$message_data['content'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['to_client_id'])) {
                    throw new \Exception("\$message_data['to_client_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                $userInfo = $_SESSION['user_info'];

                $receiveId = $message_data['to_client_id'];
                $content = $message_data['content'];
                $senderId = $userInfo['userSign'];

                if ($senderId == $receiveId) {
                    throw new \Exception("\$message_data['to_client_id'] is no validate. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                try {
                    //插入数据库
                    $userMessageService = new \Applications\Suiuu\Services\UserMessageService();

                    $userMessage = new \Applications\Suiuu\Entity\UserMessage();
                    $userMessage->senderId = $senderId;
                    $userMessage->receiveId = $receiveId;
                    $userMessage->content = $content;
                    $userMessage->sendTime = date('Y-m-d H:i:s');
                    $userMessage->isShield = 0;

                    $sessionKey = $userMessageService->addUserMessage($userMessage);

                    $all_clients = self::getClientListFromRoom($_SESSION['room_id']);

                    $receiveClientArray =  array_keys ($all_clients,$receiveId);

                    //判断用户是否在线 如果在线 推送消息 然后对方发起刷新
                    if (count($receiveClientArray)==0) {
                        //设置对方用户有为未读消息
                        self::setUserHasUnReadMessage($receiveId);
                    } else {
                        $new_message = array(
                            'type' => 'say',
                            'sender_id' => $senderId,
                            'sender_name' => htmlspecialchars($userInfo['nickname']),
                            'sender_HeadImg' => $userInfo['headImg'],
                            'receive_id' => $receiveId,
                            'content' => nl2br(htmlspecialchars($content)),
                            'time' => $userMessage->sendTime,
                            'session_key' => $sessionKey
                        );
                        foreach($receiveClientArray as $receiveClientId)
                        {
                            Gateway::sendToClient($receiveClientId, json_encode($new_message));
                            echo "推送" . $receiveClientId . "成功！";
                        }
                    }

                } catch (Exception $e) {
                    throw $e;
                }

                return;
            // 用户退出 更新用户列表
            case 'logout':
                //{"type":"logout","client_id":xxx,"time":"xxx"}
                $room_id=$_SESSION['room_id'];
                self::delClientFromRoom($room_id, $client_id);
                $_SESSION['userSign']='';
                $_SESSION['user_info']='';

        }
    }



    public static function findUserInfoByUserKey($user_key)
    {
        //$keyPrefix='6714b';$path='yii\redis\Session';
        //$redisKey=$keyPrefix . md5(json_encode([$path, $user_key]));
        $redisKey="U_L_S_C" . $user_key;
        $store = Store::instance('room');
        $date=$store->get($redisKey);
        $date=json_decode($date,true);
        return $date;
    }


    public static function setUserHasUnReadMessage($userSign)
    {
        $store = Store::instance('room');
        $store->set("U_M_U_R".$userSign,1);
    }

    public static function refreshUserMessageSession($userSign)
    {
        $store = Store::instance('room');
        $status=$store->get("U_M_U_R".$userSign,1);
        if($status==1){
            //重新查询
        }else{
            //读取session
        }
    }


    /**
     * 当客户端断开连接时
     * @param integer $client_id 客户端id
     */
    public static function onClose($client_id)
    {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
        $room_id=$_SESSION['room_id'];
        self::delClientFromRoom($client_id,$room_id);
        if(isset($_SESSION['user_info'])){
            $_SESSION['user_info']=null;
            $_SESSION['userSign']=null;
        }
    }


    /**
     * 格式化客户端列表数据
     * @param array $all_clients
     */
    public static function formatClientsData($all_clients)
    {
        $client_list = array();
        if($all_clients)
        {
            foreach($all_clients as $tmp_client_id=>$tmp_name)
            {
                $client_list[] = array('client_id'=>$tmp_client_id, 'client_name'=>$tmp_name);
            }
        }
        return $client_list;
    }

    /**
     * 获得客户端列表
     * @todo 保存有限个
     */
    public static function getClientListFromRoom($room_id)
    {
        $key = "ROOM_CLIENT_LIST-$room_id";
        $store = Store::instance('room');
        $ret = $store->get($key);
        if(false === $ret)
        {
            if(get_class($store) == 'Memcached')
            {
                if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                {
                    return array();
                }
                else
                {
                    throw new \Exception("getClientListFromRoom($room_id)->Store::instance('room')->get($key) fail " . $store->getResultMessage());
                }
            }
            return array();
        }
        return $ret;
    }

    /**
     * 从客户端列表中删除一个客户端
     * @param int $client_id
     */
    public static function delClientFromRoom($room_id, $client_id)
    {
        $key = "ROOM_CLIENT_LIST-$room_id";
        $store = Store::instance('room');
        // 存储驱动是memcached
        if(get_class($store) == 'Memcached')
        {
            $cas = 0;
            $try_count = 3;
            while($try_count--)
            {
                $client_list = $store->get($key, null, $cas);
                if(false === $client_list)
                {
                    if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                    {
                        return array();
                    }
                    else
                    {
                        throw new \Exception("Memcached->get($key) return false and memcache errcode:" .$store->getResultCode(). " errmsg:" . $store->getResultMessage());
                    }
                }
                if(isset($client_list[$client_id]))
                {
                    unset($client_list[$client_id]);
                    if($store->cas($cas, $key, $client_list))
                    {
                        return $client_list;
                    }
                }
                else
                {
                    return true;
                }
            }
            throw new \Exception("delClientFromRoom($room_id, $client_id)->Store::instance('room')->cas($cas, $key, \$client_list) fail" . $store->getResultMessage());
        }
        // 存储驱动是memcache或者file
        else
        {
            $handler = fopen(__FILE__, 'r');
            flock($handler,  LOCK_EX);
            $client_list = $store->get($key);
            if(isset($client_list[$client_id]))
            {
                unset($client_list[$client_id]);
                $ret = $store->set($key, $client_list);
                flock($handler, LOCK_UN);
                return $client_list;
            }
            flock($handler, LOCK_UN);
        }
        return $client_list;
    }

    /**
     * 添加到客户端列表中
     * @param int $client_id
     * @param string $client_name
     */
    public static function addClientToRoom($room_id, $client_id, $client_name)
    {
        $key = "ROOM_CLIENT_LIST-$room_id";
        $store = Store::instance('room');
        // 获取所有所有房间的实际在线客户端列表，以便将存储中不在线用户删除
        $all_online_client_id = Gateway::getOnlineStatus();
        // 存储驱动是memcached
        if(get_class($store) == 'Memcached')
        {
            $cas = 0;
            $try_count = 3;
            while($try_count--)
            {
                $client_list = $store->get($key, null, $cas);
                if(false === $client_list)
                {
                    if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                    {
                        $client_list = array();
                    }
                    else
                    {
                        throw new \Exception("Memcached->get($key) return false and memcache errcode:" .$store->getResultCode(). " errmsg:" . $store->getResultMessage());
                    }
                }
                if(!isset($client_list[$client_id]))
                {
                    // 将存储中不在线用户删除
                    if($all_online_client_id && $client_list)
                    {
                        $all_online_client_id = array_flip($all_online_client_id);
                        $client_list = array_intersect_key($client_list, $all_online_client_id);
                    }
                    // 添加在线客户端
                    $client_list[$client_id] = $client_name;
                    // 原子添加
                    if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                    {
                        $store->add($key, $client_list);
                    }
                    // 置换
                    else
                    {
                        $store->cas($cas, $key, $client_list);
                    }
                    if($store->getResultCode() == \Memcached::RES_SUCCESS)
                    {
                        return $client_list;
                    }
                }
                else
                {
                    return $client_list;
                }
            }
            throw new \Exception("addClientToRoom($room_id, $client_id, $client_name)->cas($cas, $key, \$client_list) fail .".$store->getResultMessage());
        }
        // 存储驱动是memcache或者file
        else
        {
            $handler = fopen(__FILE__, 'r');
            flock($handler,  LOCK_EX);
            $client_list = $store->get($key);
            if(!isset($client_list[$client_id]))
            {
                // 将存储中不在线用户删除
                if($all_online_client_id && $client_list)
                {
                    $all_online_client_id = array_flip($all_online_client_id);
                    $client_list = array_intersect_key($client_list, $all_online_client_id);
                }
                // 添加在线客户端
                $client_list[$client_id] = $client_name;
                $ret = $store->set($key, $client_list);
                flock($handler, LOCK_UN);
                return $client_list;
            }
            flock($handler, LOCK_UN);
        }
        return $client_list;
    }
}
