<?php
/**
 * EuroSMS REST API interface for PHP. | PHP rozhranie pre EuroSMS REST API.
 */

if (!defined('EUROSMS_DEBUG')) {
  define('EUROSMS_DEBUG', false);
}

require_once (__DIR__ . '/manager.php');
require_once (__DIR__ . '/../3rdparty/libphonenumber/autoload.php');

/**
 * Base class | Základná trieda
 */
class EuroSmsBase {
}

/**
 * SMS message | SMS správa
 */
class EuroSmsMessage extends EuroSmsBase {

  /**
   * Default length of one SMS message | Predvolená dĺžka jednej SMS správy
   */
  const DEFAULT_MESSAGE_LENGTH = 160;

  /**
   * Default length of next SMS message | Predvolená dĺžka nasledujúcej SMS správy
   */
  const DEFAULT_NEXT_MESSAGE_LENGTH = 153;

  /**
   * Default length of one SMS message (with Unicode characters) | Predvolená dĺžka jednej SMS správy (s Unikódovými znakmi)
   */
  const UNICODE_MESSAGE_LENGTH = 70;

  /**
   * Default length of next SMS message (with Unicode characters) | Predvolená dĺžka nasleduúcej SMS správy (s Unikódovými znakmi)
   */
  const UNICODE_NEXT_MESSAGE_LENGTH = 67;

  /**
   * Recipients' telephone numbers (will be converted to international format E.164) | Telefónne čísla adresátov (budú prevedené na medzinárodný formát E.164)
   */
  public $recipients = array();

  /**
   * Message sender name | Názov odosielateľa správy
   */
  public $sender = '';

  /**
   * Unicode characters allowed | Unikódové znaky povolené
   */
  public $unicode = false;

  /**
   * Message text | Text správy
   */
  public $text = '';

  /**
   * UNIX scheduled sending timestamp | UNIXová časová značka plánovaného odoslania
   *
   * @see EuroSms::sendMessage()
   */
  public $schedule_timestamp = 0;

  /**
   * UNIX send timestamp | UNIXová časová značka odoslania
   *
   * @see EuroSms::sendMessage()
   */
  public $timestamp = 0;

  /**
   * Send error text | Text chyby odosielania
   *
   * @see EuroSms::sendMessage()
   */
  public $error = '';

  /**
   * Send status text | Text stavu odosielania
   *
   * @see EuroSms::sendMessage()
   */
  public $status = '';

  /**
   * Unique identifier of SMS message(s) in EuroSMS API | Jedinečný identifikátor SMS správy v EuroSMS API
   *
   * @see EuroSms::sendMessage()
   */
  public $sms_id = '';

  /**
   * Unique identifier of group of SMS messages in EuroSMS API | Jedinečný identifikátor skupiny SMS správ v EuroSMS API
   *
   * @see EuroSms::sendMessage()
   */
  public $group_id = '';

  /**
   * SMS count | Počet SMS
   *
   * @see EuroSms::sendMessage()
   */
  public $sms_count = 0;

  /**
   * UNIX delivery timestamp | UNIXová časová značka doručenia
   *
   * @see EuroSms::getMessageDeliveryStatus()
   */
  public $delivery_timestamp = 0;

  /**
   * SMS price returned by API | Cena za SMS vrátená z API
   *
   * @see EuroSms::getMessageDeliveryStatus()
   */
  public $price = 0.00;
}

/**
 * API wrapper class | Trieda zaobaľujúca API
 */
class EuroSms extends EuroSmsBase {
  /**
   * Message send method: One to One | Metóda odoslania správy: Jeden na Jeden
   *
   * When using this method, every message from $messages list is sent through separate API request to every recipient in $recipients list, so for 2 messages with 2 recipients per each, 4 API requests are made. | Pri použití tejto metódy, každá správa zo zoznamu $messages je odoslaná cez samostaný API dotaz pre každého príjemcu zo zoznamu $recipients, t.j. pre 2 správy s 2 príjemcami každej správy sú vykonané 4 API dotazy.
   *
   * @see EuroSms::sendMessage()
   */
  const SEND_METHOD_O2O = EuroSmsManager::SEND_METHOD_O2O;

  /**
   * Message send method: One to Many | Metóda odoslania správy: Jeden na Viac
   *
   * When using this method, every message from $messages list is sent through separate API request to all recipients in $recipients list, so for 2 messages with 2 recipients per each, 2 API requests are made. | Pri použití tejto metódy, každá správa zo zoznamu $messages je odoslaná cez samostaný API dotaz pre všetkých príjemcov zo zoznamu $recipients, t.j. pre 2 správy s 2 príjemcami každej správy sú vykonané 2 API dotazy.
   *
   * @see EuroSms::sendMessage()
   */
  const SEND_METHOD_O2M = EuroSmsManager::SEND_METHOD_O2M;

  /**
   * Message send method: Many to Many | Metóda odoslania správy: Viac na Viac
   *
   * When using this method, all messages from $messages list are sent through single API request to all recipients in theirs $recipients list, so for 2 messages with 2 recipients per each, 1 API request is made. | Pri použití tejto metódy, všetky správy zo zoznamu $messages sú odoslané cez jediný API dotaz pre všetkých príjemcov zo zoznamu $recipients, t.j. pre 2 správy s 2 príjemcami každej správy je vykonaný 1 dotaz.
   *
   * @see EuroSms::sendMessage()
   */
  const SEND_METHOD_M2M = EuroSmsManager::SEND_METHOD_M2M;

  /**
   * Message delivery status method: One | Metóda stavu doručenia: Jeden
   *
   * When using this method, delivery status for every message from $messages list is got through one API request per every message. | Pri použití tejto metódy, stav doručenia pre každú správu zo zoznamu $messages je získaný cez jeden API dotaz pre každú správu.
   *
   * @see EuroSms::getMessageDeliveryStatus()
   */
  const DELIVERY_STATUS_METHOD_ONE = EuroSmsManager::DELIVERY_STATUS_METHOD_ONE;

  /**
   * Message delivery status method: Any | Metóda stavu doručenia: Akýkoľvek
   *
   * When using this method, delivery status for all messages from $messages list is got through single API request. | Pri použití tejto metódy, stav doručenia pre všetky správy zo zoznamu $messages je získaný cez jediný API dotaz.
   *
   * @see EuroSms::getMessageDeliveryStatus()
   */
  const DELIVERY_STATUS_METHOD_ANY = EuroSmsManager::DELIVERY_STATUS_METHOD_ANY;

  /**
   * Message delivery status method: Group | Metóda stavu doručenia: Skupinový
   *
   * When using this method, delivery status for all messages from $messages list is got through separate API request for every distinct message group $group_id. | Pri použití tejto metódy, stav doručenia pre všetky správy zo zoznamu $messages je získaný cez samostatný API dotaz pre každú odlišnú skupinu správ $group_id.
   *
   * @see EuroSms::getMessageDeliveryStatus()
   */
  const DELIVERY_STATUS_METHOD_GROUP = EuroSmsManager::DELIVERY_STATUS_METHOD_GROUP;

  /**
   * Internal API manager object | Vnútorný objekt pre správu API
   */
  private $mgr = NULL;

  /**
   * Default country for phone number operations | Predvolená krajina pre operácie s telefónnymi číslami
   */
  protected $default_country = NULL;

  /**
   * Initialize class | Inicializuj triedu
   *
   * @param string $user API user name. | API meno používateľa.
   * @param string $password API user password. | API heslo používateľa.
   * @param string $default_country Default country code for phone number operations. | Predvolená krajina pre operácie s telefónnymi číslami.
   *
   * @return void
   */
  public function __construct($user = NULL, $password = NULL, $default_country = 'SK') {
    if (!$user || !$password) {
      throw new EuroSmsException(t('User or Password not specified.'));
    }

    $this->mgr = new EuroSmsManager(array(
      'eurosms' => (object)array(
        'authenticate' => array(
          'api_id' => $user,
          'api_key' => $password
        )
      )
    ));

    $this->default_country = $default_country;
  }

  /**
   * Format phone number to international E.164 format | Uprav telefónne číslo do medzinárodného formátu E.164
   *
   * @param string $number Phone number. | Telefónne číslo.
   *
   * @return string Formatted phone number, or FALSE on error. | Upravené telefónne číslo, alebo FALSE pri chybe.
   */
  protected function formatPhoneNumber($number) {
    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    try {
      $phone_parsed = $phoneUtil->parse($number, $this->default_country);
      $phone_formatted = str_replace(' ', '', $phoneUtil->format($phone_parsed, \libphonenumber\PhoneNumberFormat::INTERNATIONAL));
    }
    catch(\libphonenumber\NumberParseException $e) {
      $phone_formatted = false;
    }

    return $phone_formatted;
  }

  /**
   * Send message(s) | Odoslať správu/y
   *
   * @param EuroSmsMessage[] $messages List of messages to send. | Zoznam správ na odoslanie.
   * @param string $method Send method. | Metóda odoslania (EuroSms::SEND\_METHOD\_\*).
   *
   * @throws EuroSmsException If exception occurs during request processing in PHP interface. | Ak nastane výnimka pri spracovaní dotazu v PHP rozhraní.
   *
   * @return EuroSmsMessage[]  List of messages with their send statuses (or FALSE or API error description string). | Zoznam správ s ich stavmi odoslania (alebo FALSE alebo textový popis chyby z API).
   */
  public function sendMessage($messages = array() , $method = EuroSms::SEND_METHOD_M2M) {
    if (!$messages) {
      return array();
    }

    $msgList = array();
    foreach ($messages as & $message) {
      $msg = new stdClass();

      $msg->sender = (string)$message->sender;
      $msg->unicode = (bool)$message->unicode;
      $msg->text = (string)$message->text;

      $message->timestamp = time();

      if ($message->schedule_timestamp) {
        $msg->timestamp = (int)$message->schedule_timestamp;
      }

      $msgLen = $msg->unicode ? EuroSmsMessage::UNICODE_MESSAGE_LENGTH : EuroSmsMessage::DEFAULT_MESSAGE_LENGTH;
      $msgNextLen = $msg->unicode ? EuroSmsMessage::UNICODE_NEXT_MESSAGE_LENGTH : EuroSmsMessage::UNICODE_MESSAGE_LENGTH;
      $textLen = strlen($msg->text);
      $msg->sms_count = ceil(max(0, $textLen - $msgLen) / $msgNextLen) + 1;

      foreach ((array)$message->recipients as $recipient) {
        $msgEntry = clone ($msg);
        $msgEntry->recipient = $this->formatPhoneNumber($recipient);
        if (!$msgEntry->recipient) {
          continue;
        }

        $msgEntry->id = md5(serialize($msgEntry));
        $msgEntry->origin = $message;
        $msgList[] = $msgEntry;
      }
    }

    $sentMsgList = $this->mgr->sendMessage($msgList, $method, array(
      'eurosms'
    ));

    if (isset($sentMsgList['eurosms'])) {
      if (isset($sentMsgList['eurosms']['error'])) {
        return implode(EUROSMS_ERROR_SEPARATOR, (array)$sentMsgList['eurosms']['error']);
      }
      else if ($sentMsgList['eurosms'] === false) {
        return false;
      }
    }

    $sentMessages = array();
    foreach ($sentMsgList as $sentMsg) {
      $sentMessage = clone ($sentMsg->origin);
      $sentMessage->recipients = array(
        $sentMsg->recipient
      );

      foreach (array(
        'sms_count',
        'timestamp',
        'error',
        'status',
        'sms_id',
        'group_id'
      ) as $property) {
        if (isset($sentMsg->$property)) {
          $sentMessage->$property = $sentMsg->$property;
        }
      }

      $sentMessages[] = $sentMessage;
    }

    return $sentMessages;
  }

  /**
   * Get delivery status of message(s) | Získať stav doručenia správ(y)
   *
   * @param EuroSmsMessage[] $messages List of sent messages. | Zoznam odoslaných správ (EuroSms::sendMessage()).
   * @param string $method Delivery status method. | Metóda stavu doručenia (EuroSms::DELIVERY\_STATUS\_METHOD\_\*).
   *
   * @throws EuroSmsException If exception occurs during API request processing in PHP interface. | Ak nastane výnimka pri spracovaní API dotazu v PHP rozhraní.
   *
   * @return EuroSmsMessage[] List of messages with their delivery statuses (or error description string). | Zoznam správ s ich stavmi doručenia (alebo textovým popisom chyby).
   */
  public function getMessageDeliveryStatus($messages = array() , $method = EuroSms::DELIVERY_STATUS_METHOD_ANY) {
    if (!$messages) {
      return array();
    }

    $msgList = array();
    foreach ($messages as $message) {
      $msg = new stdClass();

      $msg->group_id = $message->group_id;
      $msg->sms_id = $message->sms_id;
      $msg->price = $message->price;

      if (/*!$msg->group_id || */!$msg->sms_id) {
        continue;
      }

      $msg->origin = $message;
      $msgList[] = $msg;
    }

    $deliveredMsgList = $this->mgr->getMessageStatus($msgList, $method, array(
      'eurosms'
    ));

    if ($deliveredMsgList === false) {
      return false;
    }

    if (isset($deliveredMsgList['eurosms'])) {
      if (isset($deliveredMsgList['eurosms']['error'])) {
        return implode(EUROSMS_ERROR_SEPARATOR, (array)$deliveredMsgList['eurosms']['error']);
      }
      else if ($deliveredMsgList['eurosms'] === false) {
        return false;
      }
    }

    $deliveredMessages = array();
    foreach ($deliveredMsgList as $deliveredMsg) {
      $deliveredMessage = clone ($deliveredMsg->origin);
      foreach (array(
        'price',
        'delivery_timestamp',
        'error',
        'status'
      ) as $property) {
        if (isset($deliveredMsg->$property)) {
          $deliveredMessage->$property = $deliveredMsg->$property;
        }
      }

      $deliveredMessages[] = $deliveredMessage;
    }

    return $deliveredMessages;
  }
}
