<?php
/**
 * Created by PhpStorm.
 * User : xin.zhang
 * Date : 15/5/7
 * Time : 下午4:16
 * Email: zhangxinmailvip@foxmail.com
 */

namespace Applications\Suiuu\Entity;


class UserMessageSession {



    public $sessionId;

    public $userId;

    public $relateId;

    public $sessionKey;

    public $lastConcatTime;

    public $lastContentInfo;

    public $isRead;

    public $unReadCount;

}