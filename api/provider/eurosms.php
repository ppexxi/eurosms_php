<?php
require_once (__DIR__ . '/../common.php');

define('EUROSMS_MAX_SENDER_LENGTH', 11);

define('EUROSMS_ERROR_MESSAGE_ENQUEUED', 'Message enqueued');
define('EUROSMS_ERROR_WRONG_NUMBER', 'Invalid number specified');
define('EUROSMS_ERROR_UNKNOWN', 'Unknown error');

class EuroSmsProviderEuroSms extends EuroSmsApi implements EuroSmsProvider {

  private $api_id;
  private $api_key;

  public function authenticate($params = NULL) {
    $this->api_id = $params->api_id;
    $this->api_key = $params->api_key;
  }

  public function sendMessage($msgList, $method = EuroSmsManager::SEND_METHOD_M2M) {
    if (!$msgList) {
      return array();
    }

    if ($method) {
      $method = strtolower($method);
    }

    $firstMessage = reset($msgList);

    $request = new stdClass();
    $request->iid = $this->api_id;
    $request->rsp = 'Full';
    $request->dsndr = substr($firstMessage->sender, 0, EUROSMS_MAX_SENDER_LENGTH);
    if ($firstMessage->unicode) {
      $request->dflgs = 'unicode long';
    }
    else {
      $request->dflgs = 'long';
    }

    if (!empty($firstMessage->timestamp)) {
      if ((is_numeric($firstMessage->timestamp) && ($t = (int)$firstMessage->timestamp)) || ($t = strtotime($firstMessage->timestamp)) && ($t > time())) {
        $request->start = new DateTime('@' . $t);
        $request->start->setTimezone(new DateTimeZone(date_default_timezone_get()));
      }
    }

    $request->msgs = array();

    $methodFnc = 'send' . strtoupper($method);

    $responses = array();

    $last = count($msgList) - 1;
    foreach ($msgList as $idx => $msg) {

      $sndr = substr($msg->sender ? $msg->sender : $request->dsndr, 0, EUROSMS_MAX_SENDER_LENGTH);
      $rcpt_num = EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL . ($rcpt = trim($msg->recipient, '+')) . EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL;

      $sch = null;
      if (!empty($msg->timestamp)) {
        if ((is_numeric($msg->timestamp) && ($t = (int)$msg->timestamp)) || ($t = strtotime($msg->timestamp)) && ($t > time())) {
          $sch = new DateTime('@' . $t);
          $sch->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
      }

      switch ($method) {
        case EuroSmsManager::SEND_METHOD_O2O:

          $message = (object)array(
            'iid' => $request->iid,
            'flgs' => $msg->unicode ? 'unicode long' : 'long',
            'txt' => $msg->text,
            'rcpt' => $rcpt_num,
            'sndr' => $sndr,
            'sgn' => $this->calcSignature(array(
              $sndr,
              $rcpt,
              $msg->text
            ))
          );

          if (isset($sch)) {
            $message->sch = $sch;
          }

          $response = $this->executeMethod('EuroSMS.Request', $methodFnc, array(
            $message
          ));

          if ($response) {
            $response->result = array();
            if ($response->uuid) {
              $response->result[] = (object)array(
                'f' => $msg->id,
                'i' => $response->uuid,
                'e' => EUROSMS_ERROR_MESSAGE_ENQUEUED
              );
            }
          }

          $responses[] = $response;

        break;

        case EuroSmsManager::SEND_METHOD_O2M:

          $hash = sha1($msg->text);

          $message = (object)array(
            'iid' => $request->iid,
            'flgs' => $msg->unicode ? 'unicode long' : 'long',
            'txt' => $msg->text,
            'sndr' => $sndr
          );

          if (isset($request->msgs[$hash])) {
            $request->msgs[$hash]->rcpts[] = (object)array(
              'r' => $rcpt_num,
              'f' => $msg->id,
              '_r' => $rcpt
            );
          }
          else {
            if (isset($sch)) {
              $message->start = $sch;
            }

            $message->rsp = $request->rsp;

            $request->msgs[$hash] = $message;
            $request->msgs[$hash]->rcpts = array(
              (object)array(
                'r' => $rcpt_num,
                'f' => $msg->id,
                '_r' => $rcpt
              )
            );
          }

          if ($idx >= $last) {
            foreach ($request->msgs as $req_msg) {

              $sgn = array(
                $sndr
              );

              foreach ($req_msg->rcpts as & $rcpt) {
                $sgn[] = $rcpt->_r;
                unset($rcpt->_r);
              }
              unset($rcpt);

              $sgn[] = $req_msg->txt;

              $req_msg->sgn = $this->calcSignature($sgn);

              $response = $this->executeMethod('EuroSMS.Request', $methodFnc, array(
                $req_msg
              ));

              if ($response) {
                $response->result = array();
                if (isset($response->accepted)) {
                  foreach ($response->accepted as $accepted) {
                    $response->result[] = (object)array(
                      'f' => $accepted->f,
                      'i' => $accepted->i,
                      'e' => EUROSMS_ERROR_MESSAGE_ENQUEUED
                    );
                  }
                }

                if (isset($response->wrong_numbers)) {
                  foreach ($response->wrong_numbers as $wrong) {
                    $response->result[] = (object)array(
                      'f' => $wrong->f,
                      'e' => EUROSMS_ERROR_WRONG_NUMBER
                    );
                  }
                }
              }

              $responses[] = $response;
            }
          }

        break;

        case EuroSmsManager::SEND_METHOD_M2M:

          $message = (object)array(
            'flgs' => $msg->unicode ? 'unicode long' : 'long',
            'txt' => $msg->text,
            'rcpt' => EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL . ($rcpt = trim($msg->recipient, '+')) . EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL,
            'fid' => $msg->id,
            'sndr' => $sndr,
            'sgn' => $this->calcSignature(array(
              $sndr,
              $rcpt,
              $msg->text
            ))
          );

          $request->msgs[] = $message;

          if ($idx >= $last) {
            $response = $this->executeMethod('EuroSMS.Request', $methodFnc, array(
              $request
            ));

            $responses[] = $response;
          }

        break;
      }
    }

    $accepts = array();
    $invalids = array();

    $errors = array();

    foreach ($responses as $response) {
      $current_errors = array();

      if (!$response) {
        $errors[] = EUROSMS_ERROR_UNKNOWN;
        continue;
      }

      if ($response->err_code != EUROSMS_ERROR_MESSAGE_ENQUEUED) {
        $current_errors[] = $response->err_code . (isset($response->err_desc) ? (' (' . trim($response->err_desc, '.') . ')') : '');
      }

      if (!empty($response->err_list)) {
        foreach ($response->err_list as $err_entry) {
          $current_errors[] = $err_entry->err_code . (isset($err_entry->err_desc) ? (' (' . trim($err_entry->err_desc, '.') . ')') : '');
        }
      }

      if (!empty($response->result)) {
        foreach ($response->result as $result) {
          if ($result->e == EUROSMS_ERROR_MESSAGE_ENQUEUED) {
            $result->_g = @$response->group_id;
            $accepts[$result->f] = $result;
          }
          else if (!$current_errors) {
            $invalids[$result->f] = $result;
          }
          else {
            $result->e = implode(EUROSMS_ERROR_SEPARATOR, array_unique($current_errors));
            $invalids[$result->f] = $result;
          }
        }
      }
      else if ($current_errors) {
        $result = new stdClass();
        $result->f = $msg->id;
        $result->e = implode(EUROSMS_ERROR_SEPARATOR, array_unique($current_errors));  
        $invalids[$result->f] = $result;
      }

      $errors = array_merge($errors, $current_errors);
    }

    if (!$accepts && $errors) {
      return array(
        'error' => array_unique($errors)
      );
    }

    foreach ($msgList as & $msg) {
      $id = $msg->id;
      if (isset($accepts[$id])) {
        $msg->group_id = $accepts[$id]->_g;
        $msg->sms_id = implode(', ', $accepts[$id]->i);
        $msg->status = EuroSmsManager::STATUS_PENDING;
        $msg->sms_count = count($accepts[$id]->i);
      }

      if (isset($invalids[$id])) {
        $msg->error = $invalids[$id]->e;
      }
    }
    unset($msg);

    return $msgList;
  }

  public function getMessageStatus($msgList, $method = EuroSmsManager::DELIVERY_STATUS_METHOD_ANY) {

    if (!$msgList) {
      return array();
    }

    if ($method) {
      $method = strtolower($method);
    }

    $methodFnc = 'status' . ucfirst($method);
    $responses = array();

    $priceAdd = false;

    switch ($method) {
      case EuroSmsManager::DELIVERY_STATUS_METHOD_ONE:

        foreach ($msgList as $msg) {
          $response = $this->executeMethod('EuroSMS.StatusRequest', $methodFnc, array(
            'uuid' => $msg->sms_id,
          ));

          if ($response && !empty($response->dlrs)) {
            foreach ($response->dlrs as & $dlr) {
              $dlr->i = $response->i;
              $dlr->price = $response->price;
            }
          }

          $responses[] = $response;
        }

      break;

      case EuroSmsManager::DELIVERY_STATUS_METHOD_ANY:
        $priceAdd = true;
        $trxid = sha1(mt_rand());

        $response = $this->executeMethod('EuroSMS.StatusRequest', $methodFnc, array(
          'iid' => $this->api_id,
          'trxid' => $trxid,
          'sgn' => $this->calcSignature(array(
            $trxid
          ))
        ));

        $responses[] = $response;
      break;

      break;

      case EuroSmsManager::DELIVERY_STATUS_METHOD_GROUP:
        $priceAdd = true;
        $groups = array();

        foreach ($msgList as $msg) {
          if ($msg->group_id) {
            $groups[$msg->group_id] = $msg->group_id;
          }
        }

        foreach ($groups as $group_id) {
          $response = $this->executeMethod('EuroSMS.StatusRequest', $methodFnc, array(
            'iid' => $this->api_id,
            'group_id' => (int)$group_id,
            'sgn' => $this->calcSignature(array(
              (int)$group_id
            ))
          ));

          $responses[] = $response;
        }

      break;
    }

    $dlrs = array();
    $errors = array();

    foreach ($responses as $response) {
      if ($response === FALSE) {
        $errors[] = EUROSMS_ERROR_UNKNOWN;
        continue;
      }

      if ($response->err_code != 'OK') {
        $errors[] = $response->err_code . (isset($response->err_desc) ? (' (' . $response->err_desc . ')') : '');
      }

      if (!empty($response->err_list)) {
        foreach ($response->err_list as $err_entry) {
          $errors[] = $err_entry->err_code . (isset($err_entry->err_desc) ? (' (' . $err_entry->err_desc . ')') : '');
        }
      }

      if (!empty($response->dlrs)) {
        foreach ($response->dlrs as $dlr) {
          $dlrs[$dlr->i] = $dlr;
        }
      }
    }

    if ($errors) {
      return array(
        'error' => array_unique($errors)
      );
    }

    foreach ($msgList as & $msg) {
      $ids = explode(', ', $msg->sms_id);
      foreach ($ids as $id) {

        if (isset($dlrs[$id])) {
          $dlr = $dlrs[$id];
          if (!empty($dlr->dlr_time)) {
            $msg->delivery_timestamp = $dlr->dlr_time->getTimestamp();
          }

          $setPrice = false;

          switch ($dlr->dlr) {
            case 'Queued':
              $msg->status = EuroSmsManager::STATUS_PENDING;
            break;

            case 'Accepted':
              $msg->status = EuroSmsManager::STATUS_SENT;
            break;

            case 'Delivered':
              $msg->status = EuroSmsManager::STATUS_DELIVERED;
              $setPrice = true;
            break;

            case 'Undelivered':
              $msg->status = EuroSmsManager::STATUS_UNDELIVERED;
              $setPrice = true;
            break;

            case 'Expired':
            case 'Rejected':
            case 'Cancelled':
            case 'Unknown':
              $msg->status = EuroSmsManager::STATUS_UNDELIVERED;
              $msg->error = $dlr->dlr;
              $setPrice = true;
          }

          if ($setPrice && !empty($dlr->price)) {
            if ($priceAdd) {
              $msg->price += $dlr->price;
            }
            else {
              $msg->price = $dlr->price;
            }
          }
        }
      }
    }

    //var_dump($msgList); die();
    return $msgList;
  }

  protected function calcSignature($data) {
    $sgn_str = '';
    foreach ($data as $entry) {
      $sgn_str .= $entry;
    }

    $hash = hash_hmac('sha1', $sgn_str, $this->api_key);
    return $hash;
  }

  protected function getModel() {
    return $this->getBasicModel() + array(

      'datetime' => array(
        'base' => 'datetime',
        'format' => 'Y-m-d H:i:s',
        'validator' => 'basicValidator',
        'value' => new DateTime()
      ) ,

      'datetimeHM' => array(
        'base' => 'datetime',
        'format' => 'Y-m-d H:i',
        'validator' => 'basicValidator',
        'value' => new DateTime()
      ) ,

      'time' => array(
        'base' => 'datetime',
        'format' => 'H:i',
        'validator' => 'basicValidator',
        'value' => new DateTime()
      ) ,

      'EuroSMS.Request' => array(
        'type' => 'object',
        'inherits' => 'EuroSMS.Message',

        'properties' => array(
          "iid" => array(
            'type' => 'string',
            'required' => true
          ) ,
          'dsndr' => array(
            'type' => 'string',
          ) ,
          'rcpts' => array(
            'type' => 'EuroSMS.Recipient',
            'multiple' => true
          ) ,
          'msgs' => array(
            'type' => 'EuroSMS.Message',
            'multiple' => true
          ) ,
          'dflgs' => array(
            'type' => 'EuroSMS.Flags',
          ) ,
          'ttl' => array(
            'type' => 'int'
          ) ,
          "rsp" => array(
            'type' => 'enum',
            'values' => array(
              'Basic' => 'basic',
              'Full' => 'full'
            ) ,
            'required' => true
          ) ,
          "sch" => array(
            'type' => 'datetimeHM'
          ) ,
          "start" => array(
            'type' => 'datetimeHM'
          ) ,
          "end" => array(
            'type' => 'datetimeHM'
          ) ,
          "dur" => array(
            'type' => 'time'
          )
        ) ,

        'methods' => array(
          'sendO2O' => array(
            'protocol' => 'http',
            'operation' => 'POST',
            'address' => 'https://as.eurosms.com/api/v3/' . (EUROSMS_DEBUG ? 'send' : 'send') . '/one',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',
            'singleArgument' => true,

            'arguments' => array(
              array(
                'type' => 'EuroSMS.Request',
                'required' => true
              )
            ) ,

            'result' => array(
              'type' => 'EuroSMS.ResponseOne'
            )
          ) ,

          'sendO2M' => array(
            'protocol' => 'http',
            'operation' => 'POST',
            'address' => 'https://as.eurosms.com/api/v3/' . (EUROSMS_DEBUG ? 'send' : 'send') . '/o2m',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',
            'singleArgument' => true,

            'arguments' => array(
              array(
                'type' => 'EuroSMS.Request'
              )
            ) ,

            'result' => array(
              'type' => 'EuroSMS.ResponseMany',
              //'multiple' => true
              
            )
          ) ,

          'sendM2M' => array(
            'protocol' => 'http',
            'operation' => 'POST',
            'address' => 'https://as.eurosms.com/api/v3/' . (EUROSMS_DEBUG ? 'send' : 'send') . '/m2m',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',
            'singleArgument' => true,

            'arguments' => array(
              array(
                'type' => 'EuroSMS.Request'
              )
            ) ,

            'result' => array(
              'type' => 'EuroSMS.ResponseMany'
            )
          )
        )
      ) ,

      'EuroSMS.Message' => array(
        'type' => 'object',

        'properties' => array(
          'sndr' => array(
            'type' => 'string',
          ) ,
          "fid" => array(
            'type' => 'string',
          ) ,
          'rcpt' => array(
            'type' => 'string',
            'format' => null
          ) ,
          'txt' => array(
            'type' => 'string'
          ) ,
          'sgn' => array(
            'type' => 'string'
          ) ,
          'flgs' => array(
            'type' => 'EuroSMS.Flags',
          ) ,
        ) ,
      ) ,

      'EuroSMS.ResponseOne' => array(
        'type' => 'object',
        'inherits' => 'EuroSMS.ErrorEntry',
        'properties' => array(
          "uuid" => array(
            'type' => 'string',
            'multiple' => true,
          ) ,

          'err_list' => array(
            'type' => 'EuroSMS.ErrorEntry',
            'multiple' => true
          )
        )
      ) ,

      'EuroSMS.ResponseMany' => array(
        'type' => 'object',
        'properties' => array(
          'rejected' => array(
            'type' => 'int'
          ) ,

          'group_id' => array(
            'type' => 'int'
          ) ,

          'err_code' => array(
            'type' => 'EuroSMS.ErrorCode',
            'required' => true
          ) ,

          'err_list' => array(
            'type' => 'EuroSMS.ErrorEntry',
            'multiple' => true
          ) ,

          'wrong_numbers' => array(
            'type' => 'EuroSMS.WrongNumber',
            'multiple' => true
          ) ,
          
          'accepted' => array(
            'type' => 'EuroSMS.AcceptedNumber',
            'multiple' => true
          ),

          'result' => array(
            'type' => 'EuroSMS.ResultMany',
            'multiple' => true
          )
        )
      ) ,

      'EuroSMS.ResultMany' => array(
        'type' => 'object',

        'properties' => array(
          'e' => array(
            'type' => 'EuroSMS.ErrorCode'
          ) ,

          'r' => array(
            //'type' => 'int',
            'type' => 'string'
          ) ,

          'f' => array(
            'type' => 'string'
          ) ,

          'i' => array(
            'type' => 'string',
            'multiple' => true
          )
        )
      ) ,

      'EuroSMS.StatusRequest' => array(
        'type' => 'object',
        'properties' => array() ,

        'methods' => array(

          'statusOne' => array(
            'protocol' => 'http',
            'operation' => 'GET',
            'address' => 'https://as.eurosms.com/api/v3/status/one/{uuid}',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',

            'arguments' => array(
              'uuid' => array(
                'type' => 'string',
                'required' => true
              )
            ) ,

            'result' => array(
              'type' => 'EuroSMS.StatusResponseOne'
            )
          ) ,

          'statusGroup' => array(
            'protocol' => 'http',
            'operation' => 'GET',
            'address' => 'https://as.eurosms.com/api/v3/status/group/{iid}/{group_id}/{sgn}',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',

            'arguments' => array(
              'iid' => array(
                'type' => 'string',
                'required' => true

              ) ,

              'group_id' => array(
                'type' => 'int',
                'required' => true
              ) ,

              'sgn' => array(
                'type' => 'string',
                'required' => true
              ) ,
            ) ,

            'result' => array(
              'type' => 'EuroSMS.StatusResponseMany'
            )
          ) ,

          'statusAny' => array(
            'protocol' => 'http',
            'operation' => 'GET',
            'address' => 'https://as.eurosms.com/api/v3/status/any/{iid}/{trxid}/{sgn}',
            'requestContentCodec' => 'application/json',
            'responseContentCodec' => 'application/json',

            'arguments' => array(
              'iid' => array(
                'type' => 'string',
                'required' => true

              ) ,

              'trxid' => array(
                'type' => 'string',
                'required' => true
              ) ,

              'sgn' => array(
                'type' => 'string',
                'required' => true
              ) ,
            ) ,

            'result' => array(
              'type' => 'EuroSMS.StatusResponseMany'
            )
          )
        )
      ) ,

      'EuroSMS.StatusResponseOne' => array(
        'type' => 'object',
        'inherits' => 'EuroSMS.StatusEntry',
        'properties' => array(
          'dlrs' => array(
            'type' => 'EuroSMS.StatusEntry',
            'multiple' => true
          ) ,

          'err_code' => array(
            'type' => 'string',
            'required' => true
          ) ,

          'f_id' => array(
            'type' => 'string'
          )
        )
      ) ,

      'EuroSMS.StatusResponseMany' => array(
        'type' => 'object',
        'properties' => array(
          'dlrs' => array(
            'type' => 'EuroSMS.StatusEntry',
            'multiple' => true,
            'required' => true
          ) ,
          'count' => array(
            'type' => 'int',
            'required' => true
          ) ,
          'err_code' => array(
            'type' => 'string',
            'required' => true
          )
        )
      ) ,

      'EuroSMS.StatusEntry' => array(
        'type' => 'object',
        'properties' => array(
          'rcpt' => array(
            //'type' => 'int',
            'type' => 'string',
            'format' => null
          ) ,

          'carrier' => array(
            'type' => 'string',
          ) ,

          'price' => array(
            'type' => 'decimal',
          ) ,

          'snd' => array(
            'type' => 'datetime',
          ) ,

          "i" => array(
            'type' => 'string',
          ) ,

          'dlr_time' => array(
            'type' => 'datetime'
          ) ,

          'f' => array(
            'type' => 'string'
          ) ,

          'sgmnt' => array(
            'type' => 'int'
          ) ,

          'dlr' => array(
            'type' => 'EuroSMS.StatusCode'
          )
        )
      ) ,

      'EuroSMS.Recipient' => array(
        'type' => 'object',

        'properties' => array(
          'r' => array(
            'type' => 'string',
            'required' => true
          ) ,
          'f' => array(
            'type' => 'string',
            'required' => true
          )
        )
      ) ,

      'EuroSMS.WrongNumber' => array(
        'type' => 'object',
        'properties' => array(
          'r' => array(
            'type' => 'string',
            'required' => true
          ) ,
          'f' => array(
            'type' => 'string',
          )
        )
      ) ,

      'EuroSMS.AcceptedNumber' => array(
        'type' => 'object',
        'properties' => array(
          'r' => array(
            'type' => 'string',
            'required' => true
          ) ,
          'f' => array(
            'type' => 'string',
          ) ,
          'i' => array(
            'type' => 'string',
            'required' => true,
            'multiple' => true
          )

        )
      ) ,

      'EuroSMS.Flags' => array(
        'type' => 'enum',
        'values' => array(
          'short' => 0,
          'long' => 2,
          'unicode short' => 4,
          'unicode long' => 6
        ) ,
      ) ,

      'EuroSMS.ErrorEntry' => array(
        'type' => 'object',
        'properties' => array(
          'err_code' => array(
            'type' => 'EuroSMS.ErrorCode',
            'required' => true
          ) ,
          'err_desc' => array(
            'type' => 'string',
            'required' => true,
          ) ,
        )
      ) ,

      'EuroSMS.ErrorCode' => array(
        'type' => 'enum',

        'values' => array(
          'Fail, please, verify configuration or contact supplier' => 'FAIL',
          'Failed, please, verify configuration or contact supplier' => 'FAILED',
          EUROSMS_ERROR_MESSAGE_ENQUEUED => 'ENQUEUED',
          'No API ID, please, verify configuration' => 'NO_IID',
          'No message specified' => 'NO_MSG',
          'No recipient specified' => 'NO_RCPT',
          'No text specified' => 'NO_TXT',
          'No message signature' => 'NO_SGN',
          'No sender specified' => 'NO_SNDR',
          'No recipient specified' => 'NO_RCPT',
          'Invalid key specified' => 'WRONG_KEY',
          'Invalid schedule time' => 'WRONG_SCH',
          'Low credit, please, contact supplier' => 'NO_BALANCE',
          'Invalid message signature' => 'WRONG_SIGNATURE',
          'Invalid API ID, please, verify configuration' => 'WRONG_IID',
          EUROSMS_ERROR_WRONG_NUMBER => 'WRONG_NUMBER',
          'Invalid sender specified' => 'WRONG_SENDER',
          'Empty message text' => 'EMPTY_MESSAGE',
          'Too many messages' => 'TOO_MANY_MESSAGES',
          'Message too long' => 'MSG_TOO_LONG',
          'Other error, please, verify configuration and contact supplier' => 'ERR_OTHER'
        )
      ) ,

      'EuroSMS.StatusCode' => array(
        'type' => 'enum',
        'values' => array(
          'Queued' => 'ENROUTE',
          'Accepted' => 'ACCEPTD',
          'Queued.Viber' => 'ENQUEUED',
          'Delivered' => 'DELIVRD',
          'Undelivered' => 'UNDELIV',
          'Expired' => 'EXPIRED',
          'Rejected' => 'REJECTD',
          'Cancelled' => 'DELETED',
          'Unknown' => 'UNKNOWN',
          'Read.Viber' => 'SEEN'
        )
      )
    );
  }
}
