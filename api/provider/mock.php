<?php
require_once (__DIR__ . '/../common.php');

class EuroSmsProviderMock extends EuroSmsApi implements EuroSmsProvider {

  public function sendMessage($msgList, $method = NULL) {
    $grp = mt_rand();
    foreach ($msgList as & $msg) {
      $msg->status = EuroSmsManager::STATUS_SENT;
      $msg->sms_id = md5(mt_rand());
      $msg->group_id = $grp;
    }

    return $msgList;
  }

  public function getMessageStatus($msgList, $method = NULL) {
    foreach ($msgList as & $msg) {
      $msg->status = EuroSmsManager::STATUS_DELIVERED;
    }

    return $msgList;
  }
}
