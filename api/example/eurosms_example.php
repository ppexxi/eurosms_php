<?php
/**
 * EuroSMS REST API Example. | Príklad použitia EuroSMS REST API.
 */

require ('eurosms_example.config.php');

if (EUROSMS_DEBUG) {
  error_reporting(E_ALL & ~E_NOTICE);
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
}
else {
  error_reporting(E_ERROR);
}

require_once (__DIR__ . '/../eurosms_rest_api.php');

session_start();

if (isset($_REQUEST['lang'])) {
  // set cookie for selected language | nastaviť cookie pre zvolený jazyk
  setcookie('eurosms_lang', $_REQUEST['lang'], 0, '/');
  $_COOKIE['eurosms_lang'] = $_REQUEST['lang'];
}

?>
<html>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width" />

    <title>EuroSMS REST API Example
    </title>

    <link rel="stylesheet" type="text/css" href="eurosms_example.css?<?php print time(); ?>" />
    <script type="text/javascript" src="eurosms_example.js?<?php print time(); ?>">
    </script>

    <script type="text/javascript">
      <?php
// load translations for specified language | načítať preklady pre špecifický jazyk (@see eurosms_example.js)
$langFileName = __DIR__ . '/eurosms_example.' . @$_COOKIE['eurosms_lang'] . '.json';
$langJSON = @file_get_contents($langFileName);
if ($langJSON && ($lang = @json_decode($langJSON, true))) {
  print 'window.euroSmsLanguage = ' . $langJSON;
}
else {
  print 'window.euroSmsLanguage = {};';
}

/**
 * Translate text | Preložiť text
 *
 * @param string string String to translate. | Reťazec na preklad.
 *
 * @return string Translated string. | Preložený reťazec.
 */
function t($string) {
  return isset($lang[$string]) ? $lang[$string] : $string;
}
?>
    </script>

  </head>

  <body>

    <a href="eurosms_example.php?lang=sk"/>Slovensky</a>&nbsp;<a href="eurosms_example.php?lang=en"/>English</a><br/><br/>

    <?php
if (@$_REQUEST['op'] == 'send') {
  $text = trim((string)@$_REQUEST['text']);
  $recip = trim((string)@$_REQUEST['recip']);

  if ($text && $recip) {

    // process inputs / spracovať vstupy
    $recips = array_map('trim', explode(',', $recip));
    $sender = trim((string)@$_REQUEST['sender']);

    $date = trim((string)@$_REQUEST['date']);
    $time = trim((string)@$_REQUEST['time']);

    if ($date) {
      if (!$time) {
        $time = '00:00';
      }

      $time .= ':00';

      $scheduled = strtotime($date . ' ' . $time);
    }
    else {
      $scheduled = 0;
    }

    $unicode = (bool)@$_REQUEST['unicode'];
    $sendMethod = (string)@$_REQUEST['sendMethod'];
    if (!in_array($sendMethod, array(
      EuroSms::SEND_METHOD_O2O,
      EuroSms::SEND_METHOD_O2M,
      EuroSms::SEND_METHOD_M2M
    ))) {
      $sendMethod = EuroSms::SEND_METHOD_O2O;
    }

    $messages = array();

    // prepare message object | pripraviť objekt správy
    $message = new EuroSmsMessage();
    $message->recipients = $recips;
    $message->sender = $sender;
    $message->unicode = $unicode;
    $message->text = $text;
    $message->schedule_timestamp = $scheduled;

    $messages[] = $message;

    try {

      $eurosms = new EuroSms(EUROSMS_API_USER, EUROSMS_API_PASSWORD);

      // send message | odoslať správu
      $sentMessages = $eurosms->sendMessage($messages, $sendMethod);

      $_SESSION['messageStack'] = array();
      if ($sentMessages === false) {
        // display unknown API error | zobraziť neznámu chybu API
        echo '<div class="error">' . t('Unknown API error') . '</div>';
      }
      else if (is_string($sentMessages)) {
        // display API error | zobraziť chybu API
        echo '<div class="error">' . t('API error') . ': ' . $sentMessages . '</div>';
      }
      else {
        // display result | zobraziť výsledok
        echo '<div class="info"><table><thead><tr><th>' . t('Time') . '</th><th>' . t('Status') . '</th><th>' . t('ID') . '</th><th>' . t('Count') . '</th></tr></thead>';
        foreach ($sentMessages as $sentMessage) {
          echo '<tr><td>' . date('j. n. Y H:i:s', $sentMessage->timestamp) . '</td><td>' . $sentMessage->status . ' ' . $sentMessage->error . '</td><td>' . ($sentMessage->sms_id ? ($sentMessage->sms_id . ($sentMessage->group_id ? (' / ' . $sentMessage->group_id) : '')) : '-') . '</td><td>' . $sentMessage->sms_count . '</td></tr>';
        }
        echo '</table></div>';

        // store data for delivery status | uložiť údaje pre stav doručenia
        $_SESSION['messageStack'] = $sentMessages;
      }
    }
    catch(Exception $e) {
      // display PHP interface error | zobraziť chybu PHP rozhrania
      echo '<div class="error">' . t('PHP interface error') . ': ' . t($e->getMessage()) . '</div>';
    }
  }
}
if (@$_REQUEST['op'] == 'status') {
  $sentMessages = @$_SESSION['messageStack'];

  $deliveryMethod = (string)@$_REQUEST['deliveryMethod'];
  if (!in_array($deliveryMethod, array(
    EuroSms::DELIVERY_STATUS_METHOD_ONE,
    EuroSms::DELIVERY_STATUS_METHOD_ANY,
    EuroSms::DELIVERY_STATUS_METHOD_GROUP
  ))) {
    $deliveryMethod = EuroSms::DELIVERY_STATUS_METHOD_ANY;
  }

  if ($sentMessages) {

    try {

      $eurosms = new EuroSms(EUROSMS_API_USER, EUROSMS_API_PASSWORD);

      // get status of delivered messages | získať stav doručenia správ
      $deliveredMessages = $eurosms->getMessageDeliveryStatus($sentMessages, $deliveryMethod);

      //$_SESSION['messageStack'] = array();
      if ($deliveredMessages === false) {
        // display unknown API error | zobraziť neznámu chybu API
        echo '<div class="error">' . t('Unknown API error') . '</div>';
      }
      else if (is_string($deliveredMessages)) {
        // display API error | zobraziť chybu API
        echo '<div class="error">' . t('API error') . ': ' . $deliveredMessages . '</div>';
      }
      else {
        // display result | zobraziť výsledok
        echo '<div class="info"><table><thead><tr><th>' . t('Delivery time') . '</th><th>' . t('Delivery status') . '</th><th>' . t('ID') . '</th><th>' . t('Price') . '</th></tr></thead>';
        foreach ($deliveredMessages as $deliveredMessage) {
          echo '<tr><td>' . ($deliveredMessage->delivery_timestamp ? date('j. n. Y H:i:s', $deliveredMessage->delivery_timestamp) : '-') . '</td><td>' . $deliveredMessage->status . ' ' . $sentMessage->error . '</td><td>' . $deliveredMessage->sms_id . ($deliveredMessage->group_id ? (' / ' . $deliveredMessage->group_id) : '') . '</td><td>' . $deliveredMessage->price . '</td></tr>';
        }

        if ($deliveredMessages) {
          $_SESSION['messageStack'] = $deliveredMessages;
        }
        else {
          echo '<tr><td colspan="4">-</td><tr>';
        }

        echo '</table></div>';
      }
    }
    catch(Exception $e) {
      // display PHP interface error | zobraziť chybu PHP rozhrania
      echo '<div class="error">' . t('PHP interface error') . ': ' . t($e->getMessage()) . '</div>';
    }
  }
  else {
    echo '<div class="error">' . t('No sent messages') . '</div>';
  }
}
?>

    <br/>
    <form method="post" id="eurosms" action="eurosms_example.php">

      <label for="recip">Recipient(s):
      </label>
      <input required placeholder="0900123123" type="text" id="recip" name="recip" value="<?php print @$_REQUEST['recip']; ?>">&nbsp;<small>separate by comma (,)</small>
      <br/>

      <label for="sender">Sender:
      </label>
      <input type="text" maxlength="11" required placeholder="Sender" id="sender" name="sender" value="<?php print @$_REQUEST['sender']; ?>">
      <br/>
      <br/>
      
      <label for="unicode">Unicode:
      </label>
      <input type="checkbox" id="unicode" name="unicode" 
             <?php print @$_REQUEST['unicode'] ? 'checked' : ''; ?> value="1">
      <br/>

      <label for="sendMethod">Send method:
      </label>
      <select id="sendMethod" name="sendMethod">
        <option 
          <?php print (@$_REQUEST['sendMethod'] == EuroSms::SEND_METHOD_O2O) ? 'selected' : ''; ?> value="<?php print EuroSms::SEND_METHOD_O2O; ?>">One to One
        </option>
        <option 
          <?php print (@$_REQUEST['sendMethod'] == EuroSms::SEND_METHOD_O2M) ? 'selected' : ''; ?> value="<?php print EuroSms::SEND_METHOD_O2M; ?>">One to Multiple
        </option>
        <option 
          <?php print (@$_REQUEST['sendMethod'] == EuroSms::SEND_METHOD_M2M) ? 'selected' : ''; ?> value="<?php print EuroSms::SEND_METHOD_M2M; ?>">Multiple to Multiple
        </option>
      </select>
      <br/>
      <br/>

      <label for="deliveryMethod">Delivery status method:
      </label>
      <select id="deliveryMethod" name="deliveryMethod">
        <option 
          <?php print (@$_REQUEST['deliveryMethod'] == EuroSms::DELIVERY_STATUS_METHOD_ONE) ? 'selected' : ''; ?> value="<?php print EuroSms::DELIVERY_STATUS_METHOD_ONE; ?>">One
        </option>
        <option 
          <?php print (@$_REQUEST['deliveryMethod'] == EuroSms::DELIVERY_STATUS_METHOD_ANY) ? 'selected' : ''; ?> value="<?php print EuroSms::DELIVERY_STATUS_METHOD_ANY; ?>">Any
        </option>
        <option 
          <?php print (@$_REQUEST['deliveryMethod'] == EuroSms::DELIVERY_STATUS_METHOD_GROUP) ? 'selected' : ''; ?> value="<?php print EuroSms::DELIVERY_STATUS_METHOD_GROUP; ?>">Group
        </option>
      </select>
      <br/>
      <br/>

      <label for="date">Schedule sending (date):
      </label>
      <input type="date" name="date" id="date" placeholder="YYYY-MM-DD" value="<?php print @$_REQUEST['date']; ?>">
      <br/>

      <label for="time">Schedule sending (time):
      </label>
      <input type="time" name="time" step="60" id="time" placeholder="HH-MM" value="<?php print @$_REQUEST['time']; ?>">
      <br/>
      <br/>

      <label for="text">Text:</label><br/>
      <textarea required placeholder="Message text" id="text" name="text"><?php print htmlentities(@$_REQUEST['text']); ?></textarea>
      <br/>
      <br/>

      <input type="hidden" id="op" name="op" value="">
      <input onclick="javascript: document.getElementById('op').value = 'send'; sendMessage();" type="button" id="send" name="send" value="Send">
      <input onclick="javascript: document.getElementById('op').value = 'status'; document.forms['eurosms'].submit();" type="button" id="status" name="status" value="Check delivery">
      <input type="reset" id="clear" name="clear" onclick="javascript: clearInputs(); event.preventDefault();" value="Clear form">
    </form>
  </body>
</html>