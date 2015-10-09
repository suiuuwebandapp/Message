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

    const USER_ROOM=1;
    const SYS_ROOM=2;
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
                $all_clients=self::getClientListFromRoom(self::USER_ROOM);
                $all_sys_clients=self::getClientListFromRoom(self::SYS_ROOM);
                var_dump('用户',$all_clients);
                var_dump('系统',$all_sys_clients);

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
            // 把房间号昵称放到session中
            if(array_key_exists('is_admin',$message_data)&&$message_data['is_admin']==1){
                    $userInfo = self::findSysUserInfoByUserKey($userKey);
                    if (empty($userInfo)) {
                        throw new \Exception("\$message_data['user_info'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                    }
                    $room_id=self::SYS_ROOM;
                    $userSign = htmlspecialchars($userInfo['userSign']);
                    $_SESSION['sys_room_id'] = $room_id;
                    $_SESSION['sys_user_sign'] = $userSign;
                    $_SESSION['sys_user_info'] = $userInfo;

                    self::addClientToRoom($room_id, $client_id, $userSign);

            }else{
                    $room_id = self::USER_ROOM;
                    $userInfo = self::findUserInfoByUserKey($userKey);
                    if (empty($userInfo)) {
                        throw new \Exception("\$message_data['user_info'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                    }
                    $userSign = htmlspecialchars($userInfo['userSign']);
                    $_SESSION['room_id'] = $room_id;
                    $_SESSION['userSign'] = $userSign;
                    $_SESSION['user_info'] = $userInfo;
                    self::addClientToRoom($room_id, $client_id, $userSign);

                }
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
                    $userMessage->senderId = htmlspecialchars($senderId);
                    $userMessage->receiveId = htmlspecialchars($receiveId);
                    $userMessage->content = htmlspecialchars($content);
                    $userMessage->sendTime = date('Y-m-d H:i:s');
                    $userMessage->isShield = 0;

                    $sessionKey = $userMessageService->addUserMessage($userMessage);

                    $all_clients = self::getClientListFromRoom($_SESSION['room_id']);

                    $receiveClientArray =  array_keys ($all_clients,$receiveId);

                    //判断用户是否在线 如果在线 推送消息 然后对方发起刷新
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

                    if (count($receiveClientArray)==0) {
                        //设置对方用户有为未读消息
                        self::setUserHasUnReadMessage($receiveId);
                    } else {
                        foreach($receiveClientArray as $receiveClientId)
                        {
                            Gateway::sendToClient($receiveClientId, json_encode($new_message));
                            echo "推送用户" . $receiveClientId . "成功！";
                        }
                    }
                    //判断是否是小号
                    $sysInfo=self::getSysUserInfo($receiveId);
                    if(!empty($sysInfo)){
                        $allSysClient = self::getClientListFromRoom(self::SYS_ROOM);
                        $new_message['receive_name']=$sysInfo['nickname'];
                        $new_message['receive_head_img']=$sysInfo['headImg'];

                        foreach($allSysClient as $key=>$value)
                        {
                            Gateway::sendToClient($key, json_encode($new_message));
                            echo "推送系统 key:".$key.' value:' . $value . "成功！";
                        }
                    }


                } catch (Exception $e) {
                    throw $e;
                }

                return;
            case 'sys_say':
                // 非法请求
                if (!isset($_SESSION['sys_user_info'])) {
                    throw new \Exception("\$sys_user_info not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['content'])) {
                    throw new \Exception("\$message_data['content'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['to_client_id'])) {
                    throw new \Exception("\$message_data['to_client_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['client_id'])) {
                    throw new \Exception("\$message_data['client_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['nickname'])) {
                    throw new \Exception("\$message_data['nickname'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                if (!isset($message_data['head_img'])) {
                    throw new \Exception("\$message_data['head_img'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }


                $receiveId = $message_data['to_client_id'];
                $content = $message_data['content'];
                $senderId = $message_data['client_id'];
                $nickname=$message_data['nickname'];
                $headImg=$message_data['head_img'];

                if ($senderId == $receiveId) {
                    throw new \Exception("\$message_data['to_client_id'] is no validate. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                try {
                    //插入数据库
                    $userMessageService = new \Applications\Suiuu\Services\UserMessageService();

                    $userMessage = new \Applications\Suiuu\Entity\UserMessage();
                    $userMessage->senderId = htmlspecialchars($senderId);
                    $userMessage->receiveId = htmlspecialchars($receiveId);
                    $userMessage->content = htmlspecialchars($content);
                    $userMessage->sendTime = date('Y-m-d H:i:s');
                    $userMessage->isShield = 0;

                    $sessionKey = $userMessageService->addUserMessage($userMessage);

                    $all_clients = self::getClientListFromRoom(self::USER_ROOM);

                    $receiveClientArray =  array_keys ($all_clients,$receiveId);

                    //判断用户是否在线 如果在线 推送消息 然后对方发起刷新
                    if (count($receiveClientArray)==0) {
                        //设置对方用户有为未读消息
                        self::setUserHasUnReadMessage($receiveId);
                    } else {
                        $new_message = array(
                            'type' => 'say',
                            'sender_id' => $senderId,
                            'sender_name' => htmlspecialchars($nickname),
                            'sender_HeadImg' => $headImg,
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
                if(array_key_exists('room_id',$_SESSION)){
                    $room_id=$_SESSION['room_id'];
                    self::delClientFromRoom($client_id,$room_id);
                    if(isset($_SESSION['user_info'])){
                        $_SESSION['user_info']=null;
                        $_SESSION['userSign']=null;
                    }
                }else{
                    $room_id=self::SYS_ROOM;
                    self::delClientFromRoom($client_id,$room_id);
                    $_SESSION['sys_room_id'] = null;
                    $_SESSION['sys_user_sign'] = null;
                    $_SESSION['sys_user_info'] = null;
                }

        }
    }



    public static function findUserInfoByUserKey($user_key)
    {
        //$keyPrefix='6714b';$path='yii\redis\Session';
        //$redisKey=$keyPrefix . md5(json_encode([$path, $user_key]));
        $redisKey="U_L_S_C" . $user_key;
        $store = Store::instance('room');
        $data=$store->get($redisKey);
        $data=json_decode($data,true);
        return $data;
    }
    public static function findSysUserInfoByUserKey($user_key)
    {
        $redisKey="S_U_C_S" . $user_key;
        $store = Store::instance('room');
        $date=$store->get($redisKey);
        $date=json_decode($date,true);
        return $date;
    }

    public static function getSysUserInfo($user_sign)
    {
        $redisKey="S_A_U_S_C";
        $store = Store::instance('room');
        $data=$store->get($redisKey);
        $data=json_decode($data,true);
        if(empty($data)){
            return false;
        }
        foreach($data as $temp)
        {
            if($temp['userSign']==$user_sign){
                return $temp;
            }
        }
        return null;
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
        if(array_key_exists('room_id',$_SESSION)){
            $room_id=$_SESSION['room_id'];
            self::delClientFromRoom($client_id,$room_id);
            if(isset($_SESSION['user_info'])){
                $_SESSION['user_info']=null;
                $_SESSION['userSign']=null;
            }
        }else{
            $room_id=self::SYS_ROOM;
            self::delClientFromRoom($client_id,$room_id);
            $_SESSION['sys_room_id'] = null;
            $_SESSION['sys_user_sign'] = null;
            $_SESSION['sys_user_info'] = null;
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
