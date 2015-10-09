<?php
/**
 * Created by PhpStorm.
 * User : xin.zhang
 * Date : 15/8/24
 * Time : 下午4:24
 * Email: zhangxinmailvip@foxmail.com
 */

namespace Applications\Suiuu\Services;

use Applications\Suiuu\Db\BaseDb;
use Applications\Suiuu\Db\UserMessageDb;
use Applications\Suiuu\Entity\UserMessage;
use Applications\Suiuu\Entity\UserMessageSession;
use Applications\Suiuu\Entity\UserMessageSetting;

class UserMessageService extends BaseDb{


    public $userMessageDb;

    public function addUserMessage(UserMessage $userMessage)
    {
        //系统不能作为消息的收信人
        if($userMessage->receiveId=="SYSTEM"){
            throw new \Exception("Invalid User System Message");
        }
        $receiveSetting=$this->findUserMessageSettingByUserId($userMessage->receiveId);
        $conn=$this->getConnection();
        $conn->beginTrans();

        try{
            //1.获取是否有对应的两个 sessionKey
            $sessionKey=$this->getMessageSessionKey($userMessage->senderId,$userMessage->receiveId);

            $userMessage->sessionKey=$sessionKey;

            $this->userMessageDb=new UserMessageDb($conn);
            $refuseFlag=false;//是否屏蔽接受消息
            if($receiveSetting->status==UserMessageSetting::USER_MESSAGE_SETTING_STATUS_ALLOW_ALL){
                if(!empty($receiveSetting->shieldIds)){
                    $shieldArr=explode(",",$receiveSetting->shieldIds);
                    if(in_array($userMessage->senderId,$shieldArr)){$refuseFlag=true;}
                }
            }else if($receiveSetting->status==UserMessageSetting::USER_MESSAGE_SETTING_STATUS_REFUSE_ALL){
                $refuseFlag=true;
            }

            //如果收件方 屏蔽了 发件方  无需更新收件方session
            if(!$refuseFlag){
                $receiverMessageSession=$this->userMessageDb->findUserMessageSessionByKey($userMessage->receiveId,$sessionKey);
                //发信人 Session
                if($receiverMessageSession==null||$receiverMessageSession === false){
                    $receiverMessageSession=new UserMessageSession();
                    $receiverMessageSession->sessionKey=$sessionKey;
                    $receiverMessageSession->userId=$userMessage->receiveId;
                    $receiverMessageSession->relateId=$userMessage->senderId;
                    $receiverMessageSession->lastContentInfo=$userMessage->content;
                    $receiverMessageSession->lastConcatTime=$userMessage->sendTime;
                    $receiverMessageSession->isRead=0;
                    $receiverMessageSession->unReadCount=1;

                    $this->userMessageDb->addUserMessageSession($receiverMessageSession);
                }else{
                    $unReadCount=$receiverMessageSession['unReadCount']+1;
                    $this->userMessageDb->updateUserMessageSession($receiverMessageSession['sessionKey'],$userMessage->receiveId,$userMessage->content,$userMessage->sendTime,0,$unReadCount);
                }
            }else{
                //如果屏蔽了，那么要设置userMessage 屏蔽状态
                $userMessage->isShield=1;
            }
            //无论收信人是否屏蔽发信人 发信人的 Session 一定要更新
            $senderMessageSession=$this->userMessageDb->findUserMessageSessionByKey($userMessage->senderId,$sessionKey);
            if($senderMessageSession==null||$senderMessageSession === false){
                $senderMessageSession=new UserMessageSession();
                $senderMessageSession->sessionKey=$sessionKey;
                $senderMessageSession->userId=$userMessage->senderId;
                $senderMessageSession->relateId=$userMessage->receiveId;
                $senderMessageSession->lastContentInfo=$userMessage->content;
                $senderMessageSession->lastConcatTime=$userMessage->sendTime;

                $senderMessageSession->isRead=1;
                $senderMessageSession->unReadCount=0;

                $this->userMessageDb->addUserMessageSession($senderMessageSession);
            }else{
                $this->userMessageDb->updateUserMessageSession($senderMessageSession['sessionKey'],$userMessage->senderId,$userMessage->content,$userMessage->sendTime,1,0);
            }

            $this->userMessageDb->addUserMessage($userMessage);
            $conn->commitTrans();
            return $sessionKey;

        }catch (\Exception $e){
            $conn->rollBackTrans();
            throw $e;
        }finally{
            $this->closeLink();
        }
    }

    /**
     * 获取用户系统消息设置
     * @param $userId
     * @return array|bool|UserMessageSetting|mixed|null
     * @throws \Exception
     */
    public function findUserMessageSettingByUserId($userId)
    {
        if(empty($userId)){
            throw new \Exception("Invalid UserId");
        }
        $messageSetting=null;
        try{
            $conn=$this->getConnection();
            $this->userMessageDb=new UserMessageDb($conn);
            //判断是否存在消息设置，如果没有，添加默认
            $messageSetting=$this->userMessageDb->findUserMessageSettingByUserId($userId);
            $messageSetting=$this->arrayCastObject($messageSetting,UserMessageSetting::class);
            if($messageSetting==null){
                $messageSetting=new UserMessageSetting();
                $messageSetting->userId=$userId;
                $messageSetting->shieldIds="";
                $messageSetting->status=UserMessageSetting::USER_MESSAGE_SETTING_STATUS_ALLOW_ALL;//默认接收所有消息
                $this->userMessageDb->addUserMessageSetting($messageSetting);
                $messageSetting->settingId=$this->getLastInsertId();
            }

            if(!empty($messageSetting->shieldIds)){
                $shieldArr=explode(",",$messageSetting->shieldIds);
                $shieldIds="'".implode("','",$shieldArr)."'";
                $userBaseList=$this->userMessageDb->getUserBaseByUserIds($shieldIds);
                $messageSetting->userBaseList=$userBaseList;
            }
        }catch (\Exception $e){
            throw $e;
        }finally{
            $this->closeLink();
        }
        return $messageSetting;
    }


    /**
     * 生成SessionKey
     * @param $senderId
     * @param $receiveId
     * @return string
     */
    private function getMessageSessionKey($senderId,$receiveId)
    {
        //暂时修改为两个会话
        if($senderId>$receiveId){
            return md5($senderId.$receiveId);
        }else{
            return md5($receiveId.$senderId);
        }
    }


}