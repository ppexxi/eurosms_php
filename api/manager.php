<?php
require_once ('common.php');

define('EUROSMS_PROVIDER_DIR', __DIR__ . '/provider');
define('EUROSMS_ERROR_SEPARATOR', '|');

interface EuroSmsProvider {
  public function sendMessage($msgList, $method = NULL);

  public function getMessageStatus($msgList, $method = NULL);
}

class EuroSmsManager {

  private $providers = array();

  const SEND_METHOD_O2O = 'o2o';
  const SEND_METHOD_O2M = 'o2m';
  const SEND_METHOD_M2M = 'm2m';

  const DELIVERY_STATUS_METHOD_ONE = 'one';
  const DELIVERY_STATUS_METHOD_ANY = 'any';
  const DELIVERY_STATUS_METHOD_GROUP = 'group';

  const STATUS_SENT = 'sent';
  const STATUS_DELIVERED = 'delivered';
  const STATUS_PENDING = 'pending';
  const STATUS_UNDELIVERED = 'undelivered';

  public function __construct($rootConfig = NULL) {
    foreach (scandir(EUROSMS_PROVIDER_DIR) as $file) {
      if ($file == 'index.php') {
        continue;
      }

      if (is_file(EUROSMS_PROVIDER_DIR . '/' . $file)) {
        require_once (EUROSMS_PROVIDER_DIR . '/' . $file);

        $name = basename($file, ".php");
        $ucfName = ucfirst($name);
        $ucName = strtoupper($name);

        $className = 'EuroSmsProvider' . $ucfName;

        $endPoint = @constant('EUROSMS_PROVIDER_' . $ucName . '_ENDPOINT');
        $config = @constant('EUROSMS_PROVIDER_' . $ucName . '_CONFIG');

        $config = $config ? json_decode($config) : new stdClass();

        if ($rootConfig && isset($rootConfig[$name])) {
          $obj1 = json_decode(json_encode($rootConfig[$name]) , true);
          $obj2 = json_decode(json_encode($config) , true);
          $config = json_decode(json_encode(array_merge_recursive($obj1, $obj2)));
        }

        try {
          $this->providers[$name] = new $className($endPoint, $config);
        }
        catch(\Exception $e) {
          //Logger::addLog('Error instantiating EuroSms provider "' . $name . '": ' . $e->getMessage(), 3, NULL, NULL, NULL);
          continue;
        }
      }
    }
  }

  public function getAuths($providers = NULL) {
    static $auths = NULL;

    $hash = sha1(serialize(array(
      $providers
    )));

    if (!isset($auths[$hash])) {
      $auths[$hash] = array();
      foreach ($this->providers as $name => $provider) {
        if (isset($providers) && !in_array($name, $providers)) {
          continue;
        }

        if (method_exists($provider, 'getAuth')) {
          $providerAuth = $provider->getAuth();
          $providerAuth->provider = $name;
          $auths[$hash][] = $providerAuth;
        }
      }
    }

    return $auths[$hash];
  }

  public function sendMessage($msgList, $method = EuroSmsManager::SEND_METHOD_M2M, $providers = NULL) {
    if (!$msgList) {
      return array();
    }

    if (!$method) {
      $method = EuroSmsManager::SEND_METHOD_M2M;
    }

    $results = array();
    foreach ($this->providers as $name => $provider) {
      if (isset($providers) && !in_array($name, $providers)) {
        continue;
      }

      if (method_exists($provider, 'sendMessage')) {
        $providerResults = $provider->sendMessage($msgList, $method);
        if ($providerResults === false) {
          $results[$name] = false;
        }
        else if (isset($providerResults['error'])) {
          $results[$name]['error'] = $providerResults['error'];
        }
        else {
          foreach ($providerResults as & $providerResult) {
            $providerResult->provider = $name;
          }

          $results = array_merge($results, $providerResults);
        }
      }
    }

    return $results;
  }

  public function getMessageStatus($msgList, $method = EuroSmsManager::DELIVERY_STATUS_METHOD_ANY, $providers = NULL) {
    static $msgStates = NULL;

    if (!$msgList) {
      return array();
    }

    if (!$method) {
      $method = EuroSmsManager::DELIVERY_STATUS_METHOD_ANY;
    }

    $hash = sha1(serialize(array(
      $msgList
    )));

    if (!isset($msgStates[$hash])) {
      $msgStates[$hash] = array();
      foreach ($this->providers as $name => $provider) {
        if (isset($providers) && !in_array($name, $providers)) {
          continue;
        }

        if (method_exists($provider, 'getMessageStatus')) {
          $providerResults = $provider->getMessageStatus($msgList, $method);
          if ($providerResults === false) {
            $msgStates[$hash][$name] = false;
          }
          else if (isset($providerResults['error'])) {
            $msgStates[$hash][$name]['error'] = $providerResults['error'];
          }
          else {
            foreach ($providerResults as & $providerResult) {
              $providerResult->provider = $name;
            }

            $msgStates[$hash] = array_merge($msgStates[$hash], $providerResults);
          }
        }
      }
    }

    return $msgStates[$hash];
  }

  public function executeOperation($operationName, $providers = NULL) {
    $args = func_get_args();
    array_shift($args);
    array_shift($args);

    $results = array();

    $operationName = ucfirst(strtolower($operationName));

    foreach ($this->providers as $name => $provider) {
      if (isset($providers) && !in_array($name, $providers)) {
        continue;
      }

      $methodName = 'executeOperation' . $operationName;
      if (method_exists($provider, $methodName)) {
        $results[$name] = call_user_func_array(array(
          $provider,
          $methodName
        ) , $args);
      }
    }

    return $results;
  }
}
