<?php
/**
 * Created by PhpStorm.
 * User : xin.zhang
 * Date : 15/8/24
 * Time : 下午4:51
 * Email: zhangxinmailvip@foxmail.com
 */

namespace Applications\Suiuu\Db;


use Applications\Suiuu\Entity\UserMessage;
use Applications\Suiuu\Entity\UserMessageSession;
use Applications\Suiuu\Entity\UserMessageSetting;

class UserMessageDb extends ProxyDb{


    /**
     * 添加用户消息
     * @param UserMessage $userMessage
     */
    public function addUserMessage(UserMessage $userMessage)
    {
        $sql=sprintf("
            INSERT INTO user_message
            (
              sessionKey,receiveId,senderId,url,content,sendTime,isRead,isShield
            )
            VALUES
            (
              :sessionKey,:receiveId,:senderId,:url,:content,:sendTime,FALSE,:isShield
            )
        ");

        $this->getConnection()->query($sql,[
            'sessionKey'=>$userMessage->sessionKey,
            'receiveId'=>$userMessage->receiveId,
            'senderId'=>$userMessage->senderId,
            'content'=>$userMessage->content,
            'sendTime'=>$userMessage->sendTime,
            'url'=>$userMessage->url,
            'isShield'=>$userMessage->isShield,
        ]);

    }


    /**
     * 获取会话（根据Key）
     * @param $userId
     * @param $sessionKey
     * @return array|bool
     */
    public function findUserMessageSessionByKey($userId,$sessionKey)
    {
        $sql=sprintf("
            SELECT * FROM user_message_session
            WHERE sessionKey=:sessionKey AND  userId=:userId
        ");

        $rst=$this->getConnection()->query($sql,['sessionKey'=>$sessionKey,'userId'=>$userId]);
        if(!empty($rst)){
            $rst=$rst[0];
        }
        return $rst;
    }


    /**
     * 添加用户私信session
     * @param UserMessageSession $userMessageSession
     */
    public function addUserMessageSession(UserMessageSession $userMessageSession)
    {

        $sql=sprintf("
            INSERT INTO user_message_session
            (
              sessionKey,userId,relateId,lastConcatTime,lastContentInfo,isRead
            )
            VALUES
            (
              :sessionKey,:userId,:relateId,:lastConcatTime,:lastContentInfo,:isRead
            )
        ");

        $this->getConnection()->query($sql,[
            'sessionKey'=>$userMessageSession->sessionKey,
            'lastContentInfo'=>$userMessageSession->lastContentInfo,
            'userId'=>$userMessageSession->userId,
            'relateId'=>$userMessageSession->relateId,
            'lastConcatTime'=>$userMessageSession->lastConcatTime,
            'isRead'=>$userMessageSession->isRead
        ]);
    }


    /**
     * 更新用户session详情
     * @param $sessionKey
     * @param $userId
     * @param $content
     * @param $lastConcatTime
     * @param $isRead
     */
    public function updateUserMessageSession($sessionKey,$userId,$content,$lastConcatTime,$isRead)
    {

        echo $isRead."-----------------------";
        $sql=sprintf("
          UPDATE user_message_session SET
          lastConcatTime=:lastConcatTime,lastContentInfo=:lastContentInfo,isRead=:isRead
          WHERE sessionKey=:sessionKey AND userId=:userId
        ");

        $this->getConnection()->query($sql,[
            'sessionKey'=>$sessionKey,
            'lastContentInfo'=>$content,
            'lastConcatTime'=>$lastConcatTime,
            'userId'=>$userId,
            'isRead'=>$isRead,
        ]);
    }


    /**
     * 获取用户会话列表
     * @param $userId
     * @param null $isRead
     * @return array
     */
    public function getUserMessageSessionByUserId($userId,$isRead=null)
    {
        $sql=sprintf("
            SELECT DISTINCT ub.nickname,ub.headImg,s.* FROM
            (
                SELECT sessionId,sessionKey,userId,relateId,lastConcatTime,lastContentInfo,isRead
                FROM user_message_session
                WHERE userId=:userId
            )
            AS s
            LEFT JOIN user_base ub ON ub.userSign=s.relateId
            WHERE 1=1
        ");
        if(isset($isRead)){
            $sql.=" AND s.isRead=:isRead ";
        }
        $sql.=" ORDER BY s.isRead,s.lastConcatTime DESC ";
        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":userId", $userId, PDO::PARAM_STR);

        if(isset($isRead)){
            $command->bindParam(":isRead", $isRead, PDO::PARAM_INT);
        }
        return $command->queryAll();
    }


    /**
     * 获取未读系统小心详情
     * @param $userSign
     * @return array
     */
    public function getUnReadSystemMessageList($userSign)
    {
        $sql=sprintf("
            SELECT * FROM user_message
            WHERE isRead=False AND senderId=:senderId AND receiveId=:receiveId
            ORDER BY sendTime DESC
        ");
        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":receiveId", $userSign, PDO::PARAM_STR);
        $command->bindValue(":senderId", Code::USER_SYSTEM_MESSAGE_ID, PDO::PARAM_STR);

        return $command->queryAll();
    }


    /**
     * 获取用户聊天记录列表
     * @param $userId
     * @param $sessionKey
     * @return array
     */
    public function getUserMessageListByKey($userId,$sessionKey)
    {
        $sql=sprintf("
            SELECT * FROM user_message
            WHERE sessionKey=:sessionKey
            AND
            (
              (receiveId=:userId AND isShield!=TRUE ) OR (senderId=:userId)
            )
        ");
        $command=$this->getConnection()->createCommand($sql);

        $command->bindParam(":userId", $userId, PDO::PARAM_STR);
        $command->bindParam(":sessionKey", $sessionKey, PDO::PARAM_STR);
        return $command->queryAll();
    }

    /**
     * 更新已读
     * @param $userId
     * @param $sessionKey
     */
    public function updateUserMessageSessionRead($userId,$sessionKey)
    {
        $sql=sprintf("
          UPDATE user_message_session SET
          isRead=TRUE
          WHERE sessionKey=:sessionKey AND userId=:userId
        ");

        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":sessionKey", $sessionKey, PDO::PARAM_STR);
        $command->bindParam(":userId",$userId);

        $command->execute();
    }

    /**
     * 更新已读
     * @param $sessionKey
     * @param $userSign
     */
    public function updateUserMessageRead($sessionKey,$userSign)
    {
        $sql=sprintf("
          UPDATE user_message SET
          isRead=TRUE,readTime=now()
          WHERE  sessionKey=:sessionKey AND isRead=FALSE  AND receiveId=:userId
        ");

        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":sessionKey", $sessionKey, PDO::PARAM_STR);
        $command->bindParam(":userId", $userSign, PDO::PARAM_STR);

        $command->execute();
    }

    /**
     * 更新系统消息已读
     * @param $messageId
     * @param $userSign
     
     */
    public function updateSystemMessageRead($messageId,$userSign)
    {
        $sql=sprintf("
          UPDATE user_message SET
          isRead=TRUE,readTime=now()
          WHERE isRead=FALSE  AND messageId=:messageId AND  receiveId=:receiveId
        ");

        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":messageId", $messageId, PDO::PARAM_INT);
        $command->bindParam(":receiveId", $userSign, PDO::PARAM_STR);

        $command->execute();
    }


    /**
     * 获取用户未读信息条数
     * @param $userSign
     * @param $count
     */
    public function getUnReadMessageInfoList($userSign,$count)
    {
        $sql=sprintf("
          SELECT um.*,ub.nickname,ub.headImg FROM user_message um
          LEFT JOIN user_base ON um.receiveId=ub.userSign
          WHERE isRead=FALSE AND um.receiverId=:userId
          ORDER BY um.sendTime DESC
          LIMIT 0,".$count."

        ");

        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":sessionKey", $sessionKey, PDO::PARAM_STR);
        $command->bindParam(":userId", $userSign, PDO::PARAM_STR);

        $command->execute();
    }


    /**
     * 添加用户消息设置
     * @param UserMessageSetting $userMessageSetting
     */
    public function addUserMessageSetting(UserMessageSetting $userMessageSetting)
    {
        $sql=sprintf("
            INSERT INTO user_message_setting
            (
              userId,status,shieldIds
            )
            VALUES
            (
              :userId,:status,:shieldIds
            )
        ");

        $this->getConnection()->query($sql,[
            'userId'=>$userMessageSetting->userId,
            'status'=>$userMessageSetting->status,
            'shieldIds'=>$userMessageSetting->shieldIds
        ]);


    }


    /**
     * 更新用户消息设置
     * @param UserMessageSetting $userMessageSetting
     */
    public function updateUserMessageSetting(UserMessageSetting $userMessageSetting)
    {
        $sql=sprintf("
            UPDATE user_message_setting SET
            status=:status,shieldIds=:shieldIds
            WHERE userId=:userId
        ");

        $command=$this->getConnection()->createCommand($sql);
        $command->bindParam(":userId", $userMessageSetting->userId, PDO::PARAM_STR);
        $command->bindParam(":status", $userMessageSetting->status, PDO::PARAM_INT);
        $command->bindParam(":shieldIds", $userMessageSetting->shieldIds, PDO::PARAM_STR);

        $command->execute();
    }


    /**
     * 获取用户消息设置列表
     * @param $userId
     * @return array|bool
     */
    public function findUserMessageSettingByUserId($userId)
    {
        $sql=sprintf("
            SELECT * FROM user_message_setting
            WHERE userId=:userId
        ");
        $rst=$this->getConnection()->query($sql,['userId'=>$userId]);
        if(!empty($rst)){
            $rst=$rst[0];
        }
        return $rst ;
    }

    /**
     * 批量查找用户基本信息
     * @param $userIds
     * @return array
     */
    public function getUserBaseByUserIds($userIds)
    {
        $sql=sprintf("
            SELECT nickname,surname,name,areaCode,sex,birthday,headImg,hobby,school,intro,info,travelCount,userSign,isPublisher,
            cityId,countryId,lon,lat,profession
            FROM user_base
            WHERE userSign in (".$userIds.");
        ");

        return $this->getConnection()->query($sql);


    }


}