<?php
/**
 * EuroSMS REST API Example. Send One. | Príklad použitia EuroSMS REST API. Poslať jednu.
 */

require (__DIR__ . '/../eurosms_example.config.php');

if (EUROSMS_DEBUG) {
  error_reporting(E_ALL & ~E_NOTICE);
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
}
else {
  error_reporting(E_ERROR);
}

require_once (__DIR__ . '/../../eurosms_rest_api.php');

$messages = array();

// prepare message object | pripraviť objekt správy
$message = new EuroSmsMessage();
$message->recipients = array(
  '0900123123'
);

$message->sender = 'Sender';
$message->unicode = false;
$message->text = 'My message';

// optional | nepovinný
$message->schedule_timestamp = '2019-03-04 12:10:00';

$messages[] = $message;
try {
  $eurosms = new EuroSms(EUROSMS_API_USER, EUROSMS_API_PASSWORD);
  
  // send message | odoslať správu
  $sentMessages = $eurosms->sendMessage($messages, EuroSms::SEND_METHOD_O2O);
  
  if ($sentMessages === false) {
    // display unknown API error | zobraziť neznámu chybu API
    echo 'Unknown API error';
  }
  else if (is_string($sentMessages)) {
    // display API error | zobraziť chybu API
    echo 'API error: ' . $sentMessages;
  }
  else {
    // display result | zobraziť výsledok
    foreach ($sentMessages as $sentMessage) {
      echo $sentMessage->timestamp . ': ' . $sentMessage->status . ' ' . $sentMessage->error . ', ID: ' . ($sentMessage->sms_id ? $sentMessage->sms_id : '-') . ', count: ' . $sentMessage->sms_count . '<br/>';
    }
  }
}
catch(Exception $e) {
  // display PHP interface error | zobraziť chybu PHP rozhrania
  echo 'PHP interface error: ' . $e->getMessage();
}