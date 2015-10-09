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
              sessionKey,userId,relateId,lastConcatTime,lastContentInfo,isRead,unReadCount
            )
            VALUES
            (
              :sessionKey,:userId,:relateId,:lastConcatTime,:lastContentInfo,:isRead,:unReadCount
            )
        ");

        $this->getConnection()->query($sql,[
            'sessionKey'=>$userMessageSession->sessionKey,
            'lastContentInfo'=>$userMessageSession->lastContentInfo,
            'userId'=>$userMessageSession->userId,
            'relateId'=>$userMessageSession->relateId,
            'lastConcatTime'=>$userMessageSession->lastConcatTime,
            'isRead'=>$userMessageSession->isRead,
            'unReadCount'=>$userMessageSession->unReadCount
        ]);
    }


    /**
     * 更新用户session详情
     * @param $sessionKey
     * @param $userId
     * @param $content
     * @param $lastConcatTime
     * @param $isRead
     * @param int $unReadCount
     */
    public function updateUserMessageSession($sessionKey,$userId,$content,$lastConcatTime,$isRead,$unReadCount=0)
    {

        $sql=sprintf("
          UPDATE user_message_session SET
          lastConcatTime=:lastConcatTime,lastContentInfo=:lastContentInfo,isRead=:isRead,unReadCount=:unReadCount
          WHERE sessionKey=:sessionKey AND userId=:userId
        ");

        $this->getConnection()->query($sql,[
            'sessionKey'=>$sessionKey,
            'lastContentInfo'=>$content,
            'lastConcatTime'=>$lastConcatTime,
            'userId'=>$userId,
            'isRead'=>$isRead,
            'unReadCount'=>$unReadCount
        ]);
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