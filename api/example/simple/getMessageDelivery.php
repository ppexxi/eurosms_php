<?php
/**
 * EuroSMS REST API Example. Delivery status. | Príklad použitia EuroSMS REST API. Stav doručenia.
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

// prepare message object | pripraviť objekt správy
$message = new EuroSmsMessage();
$message->sms_id = (string)@$_REQUEST['id'];   // ID of sent-message from EuroSms::sendMessage() | ID odoslanej správy z EuroSms::sendMessage()

$messages[] = $message; // You can add multiple messages | Môžete pridať viacej správ
try {
  $eurosms = new EuroSms(EUROSMS_API_USER, EUROSMS_API_PASSWORD);

  // get status of delivered messages | získať stav doručenia správ
  $deliveredMessages = $eurosms->getMessageDeliveryStatus($messages, EuroSms::DELIVERY_STATUS_METHOD_ANY);

  if ($deliveredMessages === false) {
    // display unknown API error | zobraziť neznámu chybu API
    echo 'Unknown API error';
  }
  else if (is_string($deliveredMessages)) {
    // display API error | zobraziť chybu API
    echo 'API error: ' . $deliveredMessages;
  }
  else {
    // display result | zobraziť výsledok
    foreach ($deliveredMessages as $deliveredMessage) {
      if ($deliveredMessage->delivery_timestamp) {
        echo ($deliveredMessage->delivery_timestamp ? date('j. n. Y H:i:s', $deliveredMessage->delivery_timestamp) : '-') . ': ' . $deliveredMessage->status . ' ' . $sentMessage->error . ', ID: ' . $deliveredMessage->sms_id . ($deliveredMessage->group_id ? (' / ' . $deliveredMessage->group_id) : '') . ', price: ' . $deliveredMessage->price;
      }
    }
  }
}
catch(Exception $e) {
  // display PHP interface error | zobraziť chybu PHP rozhrania
  echo 'PHP interface error: ' . $e->getMessage();
}