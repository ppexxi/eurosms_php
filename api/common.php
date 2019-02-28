<?php

define('EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL', "\000QUOTE\001");
define('EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL_JSON', trim(json_encode(EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL), '"'));

if (!function_exists('str_putcsv')) {
  function str_putcsv($input, $delimiter = ',', $enclosure = '"') {
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $input, $delimiter, $enclosure);
    rewind($fp);
    $data = fread($fp, 1048576);
    fclose($fp);
    return rtrim($data, "\n");
  }
}

function eurosms_strtotime($val) {
  if ((string)$val === ((string)intval($val))) {
    return $val;
  }
  else {
    //$val = implode(' ', $val);
    return strtotime($val);
  }
}

class JsonSerializer extends SimpleXmlElement implements JsonSerializable {
  const ATTRIBUTE_INDEX = "@";
  const CONTENT_NAME = "@";

  function jsonSerialize() {
    $array = [];

    if ($this->count()) {
      foreach ($this as $tag => $child) {
        $temp = $child->jsonSerialize();
        $attributes = [];

        foreach ($child->attributes() as $name => $value) {
          $attributes[static ::ATTRIBUTE_INDEX . "$name"] = (string)$value;
        }

        $array[$tag][] = $attributes + $temp;
      }
    }
    else {
      $temp = (string)$this;

      if (trim($temp) !== "") {
        $array[static ::CONTENT_NAME] = $temp;
      }
    }

    if ($this->xpath('/*') == array(
      $this
    )) {
      $array = [$this->getName() => $array];
    }

    return $array;
  }
}

class XMLSerializer {

  public static function Serialize($object) {
    $node = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><__wrap__></__wrap__>');
    static ::SerializeNode($node, $object);
    return str_replace(array(
      '<__wrap__>',
      '</__wrap__>'
    ) , '', $node->asXML());
  }

  private static function SerializeNode($node, $object, $parent = NULL, $is_array = false) {
    if (is_object($object)) {
      $vars = get_object_vars($object);
    }
    else if (is_array($object)) {
      $vars = $object;
    }
    else {
      throw new Exception('Invalid input to XML serializer [scalar].');
    }

    foreach ($vars as $k => $v) {
      $attr = strpos($k, '@') === 0;
      if ($attr) {
        $k = substr($k, 1);
      }

      if (is_object($v)) {
        if ($attr) {
          throw new Exception('Invalid input to XML serializer [object attribute].');
        }
        else {
          $child = $node->addChild($k);
          static ::SerializeNode($child, $v);
        }
      }
      else if (is_array($v)) {
        if ($attr) {
          throw new Exception('Invalid input to XML serializer [array attribute].');
        }
        else {
          foreach ($v as $s) {
            if (is_scalar($s)) {
              $node->addChild($k, $s);
            }
            else {
              $child = $node->addChild($k);
              static ::SerializeNode($child, $s);
            }
          }
        }
      }
      else {
        if ($attr) {
          $node->addAttribute($k, $v);
        }
        else {
          $node->addChild($k, $v);
        }
      }
    }

    return $node;
  }
}

// EUROSMS API CORE CLASSES
class EuroSmsSingleton {
  public static function getInstance() {
    static $instance = NULL;
    if (!$instance) {
      $class = get_called_class();
      $instance = new $class();
    }

    return $instance;
  }
}

class EuroSmsException extends Exception {
  public function __construct($message = NULL) {
    if (!$message) {
      $message = '<unknown>';
    }

    $args = func_get_args();
    array_shift($args);
    if (count($args) == 1) {
      $args = reset($args);
    }

    if ($args) {
      ob_start();
      print_r($args);
      $data = ob_get_clean();
      $message .= ': ' . $data;
    }

    //echo '<br/><pre style="color: red;">' . $message . '<br/>' . $this->getTraceAsString() . '</pre>';
    parent::__construct($message);
  }
}

// EUROSMS API PROTOCOL
abstract class EuroSmsProtocol extends EuroSmsSingleton {

  public function request($address, $operation, $content, $meta = array() , $options = array()) {

    if ($content && $options && @$options['requestContentCodec']) {
      $content = $options['requestContentCodec']->encode($content, $options);
    }

    $response = $this->doRequest($address, $operation, $content, $meta, $options);

    if ($response && $options && @$options['responseContentCodec']) {
      $response = $options['responseContentCodec']->decode($response, $options);
    }

    return $response;
  }

  protected abstract function doRequest($address, $operation, $content, $meta = array() , $options = array());

}

class EuroSmsProtocolHttp extends EuroSmsProtocol {

  public function __construct() {
    static $initialized = false;

    if (!$initialized) {
      $initialized = true;
      require_once (__DIR__ . '/../3rdparty/Httpful/Bootstrap.php');
      \Httpful\Bootstrap::init();
    }
  }

  protected function doRequest($address, $operation, $content, $meta = array() , $options = array()) {
    $url = $address;
    $method = $operation;
    $headers = $meta;

    $method = strtolower($method);
    if (isset($options['requestContentCodecType']) && !isset($headers['Content-Type'])) {
      $headers['Content-Type'] = $options['requestContentCodecType'];
    }

    $req = \Httpful\Request::$method($url)->addHeaders($headers)->expects('text/plain')->body($content);
    //$req->_debug = EUROSMS_DEBUG;
    $req->_curlPrep();

    if ($req->_debug) {
      $fpath = tempnam(__DIR__, 'eurosms') . '.log';
      $f = fopen($fpath, 'w');
      curl_setopt($req->_ch, CURLOPT_STDERR, $f);
      fwrite($f, $content . "\n\n");
    }

    $response = $req->send();

    if ($req->_debug) {
      fwrite($f, $response);
      fclose($f);
      if ($req->_debug) {
        echo '<br/><pre>' . file_get_contents($fpath) . '</pre><br/>';
      }

      unlink($fpath);
    }

    return $response->body;
  }
}

class EuroSmsProtocolFunction extends EuroSmsProtocol {

  protected function doRequest($address, $operation, $content, $meta = array() , $options = array()) {
    $object = $address;
    $method = $operation;
    $args = $content;

    $response = call_user_func_array($object ? array(
      $object,
      $method
    ) : $method, $args);

    return $response;
  }
}

// EUROSMS API CONTENT CONVERTOR
abstract class EuroSmsContentCodec extends EuroSmsSingleton {
  public abstract function encode($content, $options = NULL);

  public abstract function decode($content, $options = NULL);
}

class EuroSmsContentCodecTextCsv extends EuroSmsContentCodec {
  public function encode($content, $options = NULL) {
    if (!isset($options['method']['csvDelimiter'])) {
      $options['method']['csvDelimiter'] = ',';
    }

    if (!isset($options['method']['csvEnclosure'])) {
      $options['method']['csvEnclosure'] = '"';
    }

    $content = (array)$content;
    $head = array_keys($content);

    $result = '';
    $result .= str_putcsv($head, $options['method']['csvDelimiter'], $options['method']['csvEnclosure']) . "\n";
    foreach ($content as $entry) {
      $result .= str_putcsv($entry, $options['method']['csvDelimiter'], $options['method']['csvEnclosure']) . "\n";
    }

    return trim($result);
  }

  public function decode($content, $options = NULL) {
    $content_split = explode("\n", $content);
    if (!isset($options['method']['csvDelimiter'])) {
      $options['method']['csvDelimiter'] = ',';
    }

    if (!isset($options['method']['csvEnclosure'])) {
      $options['method']['csvEnclosure'] = '"';
    }

    $head = NULL;
    $result = array();
    foreach ($content_split as $line) {
      $line = trim($line, "\r\n");
      if ($head) {
        $csv_line = str_getcsv($line, $options['method']['csvDelimiter'], $options['method']['csvEnclosure']);
        if (count($head) > count($csv_line)) {
          $csv_line += array_fill(count($csv_line) , count($head) - count($csv_line) , '');
        }

        $entry = array_combine($head, $csv_line);
        $entryObj = new stdClass();
        foreach ($entry as $key => $val) {
          $entryObj->$key = $val;
        }

        $result[] = $entryObj;
      }
      else {
        $head = str_getcsv($line, $options['method']['csvDelimiter'], $options['method']['csvEnclosure']);
      }
    }

    return $result;
  }
}

class EuroSmsContentCodecTextXml extends EuroSmsContentCodec {
  public function encode($content, $options = NULL) {
    $xml = XMLSerializer::serialize((object)$content);
    return $xml;
  }

  public function decode($content, $options = NULL) {
    $json = new JsonSerializer($content);
    $result = $json->jsonSerialize();
    $result = json_decode(json_encode($result));
    return $result;
  }
}

class EuroSmsContentCodecApplicationJson extends EuroSmsContentCodec {
  public function encode($content, $options = NULL) {
    $result = json_encode($content, JSON_PRETTY_PRINT);
    $result = str_replace(array('"' . EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL_JSON, EUROSMS_CODEC_APPLICATION_JSON_QUOTE_REMOVAL_JSON . '"'), '', $result);
    //var_dump($result); die();
    return $result;
  }

  public function decode($content, $options = NULL) {
    $result = json_decode($content);
    if (!isset($result)) {
      throw new EuroSmsException(__CLASS__ . ' content decoding error', $content);
    }

    return $result;
  }
}

class EuroSmsContentCodecApplicationXWwwFormUrlEncoded extends EuroSmsContentCodec {
  public function encode($content, $options = NULL) {
    $result = http_build_query($content);
    return $result;

  }

  public function decode($content, $options = NULL) {
    $result = NULL;
    parse_str($content, $result);
    if (!isset($result)) {
      throw new EuroSmsException(__CLASS__ . ' content decoding error', $content);
    }

    return $result;
  }
}

class EuroSmsContentCodecBase64 extends EuroSmsContentCodec {
  public function encode($content, $options = NULL) {
    $result = base64_encode($content);
    return $result;

  }

  public function decode($content, $options = NULL) {
    $result = base64_decode($content);
    if ($result === false) {
      throw new EuroSmsException(__CLASS__ . ' content decoding error', $content);
    }

    return $result;
  }
}

// EUROSMS API
abstract class EuroSmsApi {

  protected $config;
  protected $endPoint;

  private $protocols = array();
  private $contentCodecs = array();

  protected $model = array();
  protected $preprocessedModel = array();

  public function __construct($endPoint, $config = NULL) {
    $this->endPoint = $endPoint;
    $this->config = $config;

    $this->registerProtocol('http', EuroSmsProtocolHttp::getInstance());
    $this->registerProtocol('function', EuroSmsProtocolFunction::getInstance());

    $this->registerContentCodec('application/json', EuroSmsContentCodecApplicationJson::getInstance());
    $this->registerContentCodec('text/csv', EuroSmsContentCodecTextCsv::getInstance());
    $this->registerContentCodec('text/xml', EuroSmsContentCodecTextXml::getInstance());
    $this->registerContentCodec('application/x-www-form-urlencoded', EuroSmsContentCodecApplicationXWwwFormUrlEncoded::getInstance());
    $this->registerContentCodec('base64', EuroSmsContentCodecBase64::getInstance());

    $this->reloadModel();
    if ($config && @$config->authenticate) {
      $this->authenticate($config->authenticate);
      $this->reloadModel();
    }
  }

  public function authenticate($params = NULL) {
    // no code
    
  }

  protected function getPreprocessedType($type, $expand = true) {
    static $preprocessedModel = array();

    $key = sha1(serialize(array(
      'type' => $type,
      'expand' => $expand
    )));

    if (!isset($preprocessedModel[$key])) {

      $model = $this->model;

      if (isset($type['base'])) {
        $preprocessedType = $type;
      }
      else {
        $typeName = $type['type'];
        if (!$typeName) {
          throw new EuroSmsException(__CLASS__ . ' invalid type', $type);
        }

        $typeType = @$model[$typeName];
        if (!$typeType) {
          throw new EuroSmsException(__CLASS__ . ' invalid type', $typeName);
        }

        $typeType = $this->getPreprocessedType($typeType, false);
        $preprocessedType = $type + $typeType;
      }

      if (@$type['fixed'] && !array_key_exists('value, $type')) {
        throw new EuroSmsException(__CLASS__ . ' missing value for constant', $type);
      }

      $isClass = ($preprocessedType['base'] == 'object');

      if ($isClass) {
        $preprocessedType['properties'] = array();

        if ($expand) {
          $preprocessedType['methods'] = array();
        }
        else {
          unset($preprocessedType['methods']);
        }
      }

      if (isset($type['inherits'])) {
        if (!$isClass) {
          throw new EuroSmsException(__CLASS__ . ' invalid inheritance | unsupported type', $type);
        }

        $inheritedType = @$model[$type['inherits']];
        if (!$inheritedType) {
          throw new EuroSmsException(__CLASS__ . ' invalid inheritance | inherited type not found', $type);
        }

        $inheritedType = $this->getPreprocessedType($inheritedType, false);

        if (isset($inheritedType['properties'])) {
          $preprocessedType['properties'] = $inheritedType['properties'];
        }

        if (isset($inheritedType['methods'])) {
          $preprocessedType['methods'] = $inheritedType['methods'];
        }
      }

      if ($isClass) {

        if (isset($type['properties'])) {
          $preprocessedType['properties'] = array_merge($preprocessedType['properties'], $type['properties']);
        }

        if ($expand && isset($type['methods'])) {
          $preprocessedType['methods'] = array_merge($preprocessedType['methods'], $type['methods']);
        }

        if ($preprocessedType['properties']) {
          foreach ($preprocessedType['properties'] as $propertyName => & $propertyInfo) {
            if (isset($propertyInfo['codec'])) {
              $propertyInfo['codec'] = $this->getContentCodec($propertyInfo['codec']);
            }

            if (!isset($propertyInfo['base'])) {
              $propTypeName = $propertyInfo['type'];
              if (!$propTypeName) {
                throw new EuroSmsException(__CLASS__ . '  missing property type', $propertyInfo);
              }

              $propType = @$model[$propTypeName];
              if (!$propType) {
                throw new EuroSmsException(__CLASS__ . ' non-existing property type', $propertyInfo);
              }

              $propType = $this->getPreprocessedType($propType, false);
              $propertyInfo = $propertyInfo + $propType;
            }

            if ($expand && ($expand !== 'norelated') && isset($propertyInfo['related'])) {
              if ($propertyInfo['base'] == 'object') {
                throw new EuroSmsException(__CLASS__ . ' related not supported for object-property', $propertyInfo);
              }
              $relatedInfo = $propertyInfo['related'];

              $relatedType = @$relatedInfo['type'];
              if (!$relatedType) {
                throw new EuroSmsException(__CLASS__ . ' missing related type', $relatedInfo);
              }
              if (!isset($model[$relatedType])) {
                throw new EuroSmsException(__CLASS__ . ' non-existing related type', $relatedInfo);
              }

              $relatedMethod = @$relatedInfo['method'];
              if (!$relatedMethod) {
                throw new EuroSmsException(__CLASS__ . ' missing related method', $relatedInfo);
              }

              $relatedType = $this->getPreprocessedType($model[$relatedType], 'norelated');

              $relatedMethod = @$relatedType['methods'][$relatedMethod];
              if (!$relatedMethod) {
                throw new EuroSmsException(__CLASS__ . ' non-existing related method', $relatedInfo);
              }

              $relatedArguments = @$relatedInfo['arguments'];
              if ($relatedArguments) {
                foreach ($relatedArguments as $relatedArgumentName => $relatedArgumentValue) {
                  if (!isset($relatedMethod['arguments'][$relatedArgumentName])) {
                    throw new EuroSmsException(__CLASS__ . ' non-existing related method argument', $relatedInfo);
                  }
                }
              }

              $relatedProperty = @$relatedInfo['property'];
              if ($relatedProperty) {
                $current = $relatedMethod['result'];
                foreach ($relatedProperty as $relatedPropertyName) {
                  $current = @$current['properties'][$relatedPropertyName];
                  if (!$current) {
                    throw new EuroSmsException(__CLASS__ . ' non-existing related property', $relatedInfo);
                  }
                }

                if ($current['type'] != $propertyInfo['type']) {
                  throw new EuroSmsException(__CLASS__ . ' related property type mismatch', $relatedInfo, $current['type'], $propertyInfo['type']);
                }
              }
            }
          }
        }

        if ($expand && $preprocessedType['methods']) {
          foreach ($preprocessedType['methods'] as & $methodInfo) {
            if (isset($methodInfo['arguments'])) {
              foreach ($methodInfo['arguments'] as $argumentName => & $argumentInfo) {
                if (!isset($argumentInfo['base'])) {
                  $argTypeName = $argumentInfo['type'];
                  if (!$argTypeName) {
                    throw new EuroSmsException(__CLASS__ . ' missing method argument type', $argumentInfo);
                  }

                  $argType = $model[$argTypeName];
                  if (!$argType) {
                    throw new EuroSmsException(__CLASS__ . ' non-existing method argument type', $argumentInfo);
                  }

                  $argType = $this->getPreprocessedType($argType, false);
                  $argumentInfo = $argumentInfo + $argType;

                }
              }
            }

            if (isset($methodInfo['result'])) {
              $resultInfo = & $methodInfo['result'];

              if (!isset($resultInfo['base'])) {
                $resultTypeName = @$resultInfo['type'];
                if (!$resultTypeName) {
                  throw new EuroSmsException(__CLASS__ . ' missing method result type', $resultInfo);
                }

                $resultType = @$model[$resultTypeName];
                if (!$resultType) {
                  throw new EuroSmsException(__CLASS__ . ' non-existing method result type', $resultInfo);
                }

                $resultType = $this->getPreprocessedType($resultType, false);
                $resultInfo = $resultInfo + $resultType;
              }
            }
          }
        }

      }

      $preprocessedModel[$key] = $preprocessedType;
    }

    return $preprocessedModel[$key];
  }

  protected function getPreprocessedModel($model) {
    $preprocessedModel = array();
    foreach ($model as $name => $type) {
      $preprocessedModel[$name] = $this->getPreprocessedType($type);
    }

    return $preprocessedModel;
  }

  protected function getModel() {
    return $this->getBasicModel();
  }

  protected function reloadModel() {
    $this->model = $this->getModel();
    $this->preprocessedModel = $this->getPreprocessedModel($this->model);
  }

  protected function getBasicModel() {
    return array(
      'enum' => array(
        'base' => 'enum',
        'validator' => 'basicValidator'
      ) ,

      'array' => array(
        'base' => 'array',
        'validator' => 'basicValidator'
      ) ,

      'object' => array(
        'base' => 'object',
        'validator' => 'basicValidator',
        'variant' => true
      ) ,

      'bool' => array(
        'base' => 'bool',
        'validator' => 'basicValidator',
        'value' => false
      ) ,

      'string' => array(
        'base' => 'string',
        'validator' => 'basicValidator'
      ) ,

      'int' => array(
        'base' => 'int',
        'format' => '%d',
        'validator' => 'basicValidator',
        'value' => 0
      ) ,

      'decimal' => array(
        'base' => 'float',
        'format' => '%f',
        'validator' => 'basicValidator',
        'value' => 0
      ) ,

      'number' => array(
        'base' => 'number',
        'format' => '%f',
        'validator' => 'basicValidator',
        'value' => 0
      )

    );
  }

  protected function basicValidator($type, $value, $to = true, $mainValue = NULL) {

    if (!isset($value) || (is_scalar($value) && ((string)$value === ""))) {
      if (@$type['required']) {
        throw new EuroSmsException(__CLASS__ . ' validation error | missing required value', $value, $type, $mainValue);
      }
      else {
        return true;
      }
    }

    if (@$type['fixed'] && ($value != $type['value'])) {
      throw new EuroSmsException(__CLASS__ . ' validation error | constant value mismatch', $value, $type, $mainValue);
    }

    $isMultiple = false;

    if (isset($value)) {

      switch ($type['base']) {
        case 'object':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_object', $value)))) || !$isMultiple && !is_object($value))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | object value expected', $value, $type, $mainValue);
          }
        break;

        case 'array':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_array', $value)))) || !$isMultiple && !is_array($value))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | array value expected', $value, $type, $mainValue);
          }
        break;

        case 'enum':

          if (($isMultiple && ($to && (count($value) != count(array_intersect_key($value, $type['values']))) || !$to && (count($value) != count(array_intersect($value, $type['values'])))) || !$isMultiple && ($to && !isset($type['values'][$value]) || !$to && !in_array($value, $type['values'], true)))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | invalid enum value', $value, $type, $mainValue);
          }
        break;

        case 'float':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_float', $value))) && (count($value) != count(array_filter(array_map('is_int', $value))))) || !$isMultiple && !(is_float($value) || is_int($value)))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | float value expected', $value, $type, $mainValue);
          }
        break;

        case 'number':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_numeric', $value)))) || !$isMultiple && !is_numeric($value))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | numeric value expected', $value, $type, $mainValue);
          }
        break;

        case 'int':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_int', $value)))) || !$isMultiple && !is_int($value))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | integer value expected', $value, $type, $mainValue);
          }
        break;

        case 'bool':
          if (($isMultiple && (count($value) != count(array_filter(array_map('is_bool', $value)))) || !$isMultiple && !is_bool($value))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | boolean value expected', $value, $type, $mainValue);
          }
        break;

        case 'datetime':
          if ($to && ($isMultiple && (count($value) != count(array_filter(array_map(function ($a) {
            return $a instanceof DateTime;
          }
          , $value)))) || !$isMultiple && !($value instanceof DateTime)) || !$to && (($isMultiple && (count($value) != count(array_filter(array_map('eurosms_strtotime', $value)))) || !$isMultiple && !eurosms_strtotime($value)))) {
            throw new EuroSmsException(__CLASS__ . ' validation error | date/time value expected', $value, $type, $mainValue);
          }
        break;
      }
    }
  }

  protected function makeUrl($url = '') {
    return $this->endPoint . $url;
  }

  public function registerProtocol($name, EuroSmsProtocol $protocol) {
    $this->protocols[$name] = $protocol;
  }

  public function unregisterProtocol($name) {
    unset($this->protocols[$name]);
  }

  public function getProtocol($name) {
    $protocol = @$this->protocols[$name];
    if (!$protocol) {
      throw new EuroSmsException(__CLASS__ . ' error getting protocol', $name);
    }

    return $protocol;
  }

  protected function getProtocolNameByEndPoint($endPoint = NULL) {
    if (!$endPoint) {
      $endPoint = $this->endPoint;
    }

    if ($endPoint) {
      $endPointSplit = explode('://', $endPoint, 2);
      return isset($endPointSplit[1]) ? $endPointSplit[0] : false;
    }
  }

  public function getProtocolByEndPoint($endPoint = NULL) {
    $protocolName = $this->getProtocolNameByEndPoint($endPoint);
    if ($protocolName) {
      return $this->getProtocol($protocolName);
    }
  }

  public function registerContentCodec($contentType, EuroSmsContentCodec $convertor) {
    $this->contentCodecs[$contentType] = $convertor;
  }

  public function unregisterContentCodec($contentType) {
    unset($this->contentCodecs[$contentType]);
  }

  public function getContentCodec($contentType) {
    $codec = $this->contentCodecs[$contentType];
    if (!$codec) {
      throw new EuroSmsException(__CLASS__ . ' error getting content codec', $contentType);
    }

    return $codec;
  }

  protected function castTo($base, $value) {
    switch ($base) {
      case 'bool':
        $casted = (bool)$value;
      break;
      case 'string':
        $casted = (string)$value;
      break;
      case 'int':
        $casted = (int)$value;
      break;
      case 'float':
        $casted = (float)$value;
      break;
      case 'datetime':
        if (!$value) {
          $value = 0;
        }

        if (is_numeric($value)) {
          $dt = new DateTime();
          $casted = $dt->setTimestamp($value);
        }
        else if (is_string($value)) {
          $casted = new DateTime($value);
        }
        else if (!($value instanceof DateTime)) {
          throw new EuroSmsException(__CLASS__ . ' error casting value | invalid instance type', $value);
        }
        else {
          $casted = $value;
        }
        break;
      case 'array':
        $casted = (array)$value;
        break;
      default:
        $casted = $value;
    }

    return $casted;
  }

  protected function findEntry($data, $propertyNames, $propertyValue) {
    $propertyName = array_shift($propertyNames);
    if (is_array($data)) {
      foreach ($data as $entry) {
        if ($propertyNames) {
          $entry = $this->findEntry($entry, $propertyNames, $propertyValue);
          if (isset($entry)) {
            return $entry;
          }
        }
        else if ($entry->$propertyName === $propertyValue) {
          return $entry;
        }
      }
    }
    else if ($propertyNames) {
      return $this->findEntry($data->$propertyName, $propertyNames, $propertyValue);
    }
    else {
      return $data;
    }
  } 

  protected function getConvertedValues($type, $value, $to = true, $options, $_mainValue = NULL) {

    static $relatedResults = array() , $relatedResponses = array();

    $outputValue = array();
    foreach ($type as $keyName => $keyType) {
      $val = @$value[$keyName];
      if (!isset($val)) {
        continue;
      }

      $valArr = @$keyType['multiple'] ? $val : array(
        $val
      );

      if (!$val && @$keyType['multiple']) {
        $outputValue[$keyName] = array();
      }

      foreach ($valArr as $valueKey => $valueEntry) {

        if (isset($options['method']['charset'])) {
          if (is_string($valueEntry)) {
            $valueEntry = iconv($to ? 'utf-8' : $options['method']['charset'], $to ? $options['method']['charset'] : 'utf-8', $valueEntry);
          }
        }

        if (!$to && isset($keyType['codec'])) {
          $valueEntry = $keyType['codec']->decode($valueEntry);
        }

        $isObject = is_object($valueEntry) && !($valueEntry instanceof DateTime);

        if (isset($keyType['validator'])) {
          $this->{$keyType['validator']}($keyType, $valueEntry, $to, isset($_mainValue) ? $_mainValue : $value);
        }

        if ($to) {
          $valueEntry = $this->castTo($keyType['base'], $valueEntry);
        }

        if ($isObject) {
          $valueEntryObject = new stdClass();

          if ($to) {
            foreach ($keyType['properties'] as $propertyName => $propertyInfo) {
              if (@$propertyInfo['fixed'] && @$propertyInfo['required']) {
                $valueEntry->$propertyName = $propertyInfo['value'];
              }
            }
          }

          foreach ($valueEntry as $valueEntryPropertyName => $valueEntryPropertyVal) {
            if (!@$keyType['variant'] && !isset($keyType['properties'][$valueEntryPropertyName])) {
              throw new EuroSmsException(__CLASS__ . ' error converting value | missing property definition', $valueEntryPropertyName, $valueEntry);
            }

            $typedPropertyVal = isset($keyType['properties'][$valueEntryPropertyName]) ? $this->getConvertedValues(array(
              $keyType['properties'][$valueEntryPropertyName]
            ) , array(
              $valueEntryPropertyVal
            ) , $to, $options, isset($_mainValue) ? $_mainValue : $value) : $valueEntryPropertyVal;

            if ($typedPropertyVal) {
              if (is_array($typedPropertyVal)) {
                $valueEntryObject->$valueEntryPropertyName = reset($typedPropertyVal);
              }
              else {
                $valueEntryObject->$valueEntryPropertyName = $typedPropertyVal;
              }
            }
            else {
              $valueEntryObject->$valueEntryPropertyName = NULL;
            }
          }

          if ($to && isset($keyType['codec'])) {
            $valueEntry = $keyType['codec']->encode($valueEntry);
          }

          $outputValue[$keyName][$valueKey] = $valueEntryObject;
        }
        else {

          if (@$keyType['fixed'] && !isset($valueEntry)) {
            $valueEntry = $keyType['value'];
          }

          if (isset($keyType['format'])) {
            if ($to) {
              if ($keyType['base'] == 'datetime') {
                $valueEntry = $valueEntry->format($keyType['format']);
              }
              else {
                $valueEntry = sprintf($keyType['format'], $valueEntry);
             }
            }
            else {
              if ($keyType['base'] == 'datetime') {
                $valueEntry = DateTime::createFromFormat($keyType['format'], $valueEntry);
              }
              else {
                // TODO: validation
                $valueEntries = sscanf($valueEntry, $keyType['format']);
                $valueEntry = $valueEntries ? reset($valueEntries) : NULL;
              }
            }
          }
          else if ($keyType['base'] == 'enum') {
            if ($to) {
              $valueEntry = $keyType['values'][$valueEntry];
            }
            else {
              $valueEntry = array_search($valueEntry, $keyType['values']);
            }
          }

          if (!$to) {
            $valueEntry = $this->castTo($keyType['base'], $valueEntry);
          }

          if ($to && isset($keyType['codec'])) {
            $valueEntry = $keyType['codec']->encode($valueEntry);
          }

          if (!$to && isset($keyType['related']) && isset($valueEntry)) {
            $args = array();
            $relatedInfo = $keyType['related'];

            if (isset($relatedInfo['arguments'])) {
              foreach ($relatedInfo['arguments'] as $arg) {
                $args[] = ($arg == '{value}') ? $valueEntry : $arg;
              }
            }

            $responseKey = sha1(serialize(array(
              $relatedInfo['method'],
              $relatedInfo['type'],
              $args
            )));

            $resultKey = sha1(serialize(array(
              $responseKey,
              $relatedInfo['property'],
              $valueEntry
            )));

            if (!isset($relatedResults[$resultKey])) {
              if (!isset($relatedResponses[$responseKey])) {
                $relatedResponses[$responseKey] = $this->executeMethod($relatedInfo['type'], $relatedInfo['method'], $args);
              }

              $relatedResults[$resultKey] = $this->findEntry($relatedResponses[$responseKey], $relatedInfo['property'], $valueEntry);
              if (!isset($relatedResults[$resultKey])) {
                //throw new EuroSmsException(__CLASS__ . ' error converting value | no related value found', $relatedInfo, $valueEntry);
              }
            }

            $valueEntry = array(
              'value' => $valueEntry,
              'related' => $relatedResults[$resultKey]
            );
          }

          $outputValue[$keyName][$valueKey] = $valueEntry;
        }
      }

      if (!@$keyType['multiple']) {
        $outputValue[$keyName] = reset($outputValue[$keyName]);
      }
    }

    return $outputValue;
  }

  protected function convertArguments($method, $arguments, $options) {
    if (!@$method['arguments']) {
      if ($arguments) {
        throw new EuroSmsException(__CLASS__ . ' value vs. argument mismatch | method has no arguments', $arguments);
      }
      else {
        return array();
      }
    }

    $input = $this->getConvertedValues($method['arguments'], $arguments, true, $options);

    if (@$method['singleArgument'] && isset($input[0]) && (count($input) == 1)) {
      $input = $input[0];
    }

    return $input;
  }

  protected function convertResult($method, $output, $options) {
    if (!@$method['result']) {
      if ($output) {
        throw new EuroSmsException(__CLASS__ . ' response vs. result mismatch | method has no result', $output);
      }
      else {
        return NULL;
      }
    }

    $result = $this->getConvertedValues(array(
      $method['result']
    ) , array(
      $output
    ) , false, $options);

    return reset($result);
  }

  public function createInstance($typeName) {
    $preprocessedModel = $this->preprocessedModel;
    $type = $preprocessedModel[$typeName];

    if (!$type) {
      throw new EuroSmsException(__CLASS__ . ' instantiation error | type not found', $typeName);
    }

    switch ($type['base']) {
      case 'object':

        $instance = new stdClass();
        if ($type['properties']) {
          // TODO[feature]: enhance
          foreach ($type['properties'] as $propertyName => $propertyInfo) {
            $instance->$propertyName = $this->createInstance($propertyInfo['type']);

            if (array_key_exists('value', $propertyInfo)) {
              $instance->$propertyName = $propertyInfo['value'];
            }

            if (@$propertyInfo['multiple']) {
              $instance->$propertyName = isset($instance->$propertyName) ? array(
                $instance->$propertyName
              ) : array();
            }
          }
        }

      break;

      case 'enum':
        $instance = @$type['value'];
      break;

      default:
        $instance = $this->castTo($type['base'], @$type['value']);
    }

    return $instance;
  }

  public function executeMethod($typeName, $methodName, $args = array() , $meta = array() , $options = array()) {
    $preprocessedModel = $this->preprocessedModel;
    $type = $preprocessedModel[$typeName];

    if (!$type) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | type not found', $typeName);
    }

    if ($type['base'] !== 'object') {
      throw new EuroSmsException(__CLASS__ . ' method execution error | invalid type', $type);
    }

    if (!@$type['methods']) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | class doesn\'t implement any methods', $type);
    }

    $method = @$type['methods'][$methodName];
    if (!$method) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | method not found', $methodName);
    }

    $protocolName = @$method['protocol'];
    if (!$protocolName) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | no protocol found', $method);
    }

    $address = @$method['address'];
    if (!$address) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | no address found', $method);
    }

    $operation = @$method['operation'];
    if (!$operation) {
      throw new EuroSmsException(__CLASS__ . ' method execution error | no operation found', $method);
    }

    $protocol = $this->getProtocol($protocolName);

    $request = $this->convertArguments($method, $args, $options);

    $found = false;
    if (is_string($address)) {
      foreach ($request as $argName => $argVal) {
        $token = '{' . $argName . '}';
        if (strpos($address, $token) !== false) {
          $found = true;
          if (!is_scalar($argVal)) {
            if (isset($options['requestContentCodec'])) {
              $argVal = $options['requestContentCodec']->encode($argVal);
              if (isset($options['method']['charset'])) {
                $argVal = iconv('utf-8', $options['method']['charset'], $argVal);
              }
            }
            else {
              throw new EuroSmsException(__CLASS__ . ' method execution error | no request-convertor for non-scalar value', $argVal);
            }
          }

          $address = str_replace($token, rawurlencode($argVal) , $address);
          unset($request[$argName]);
        }
      }
    }

    if ($found && !$request) {
      $request = null;
    }

    $options['method'] = $method;

    if (@$method['wrapped']) {
      $request = array(
        $request
      );
    }

    if (@$method['preamble']) {
      foreach ($method['preamble'] as & $preamble) {
        if ($preamble == '{method}') {
          $preamble = $methodName;
        }
      }

      $request = array_merge($method['preamble'], $request);
    }

    if (isset($method['requestContentCodec'])) {
      $options['requestContentCodecType'] = $method['requestContentCodec'];
      $options['requestContentCodec'] = $this->getContentCodec($method['requestContentCodec']);
    }

    if (isset($method['responseContentCodec'])) {
      $options['responseContentCodecType'] = $method['responseContentCodec'];
      $options['responseContentCodec'] = $this->getContentCodec($method['responseContentCodec']);
    }

    //if ($typeName == '') {var_dump($request); echo '<br/>';}
    if (is_string($address)) {
      if (strpos($address, '://') === false) {
        $address = $this->endPoint . $address;
      }
    }

    //var_dump($request); die();

    $response = $protocol->request($address, $operation, $request, $meta, $options);
    //if ($typeName == '') {var_dump($response); die();}
    $output = $this->convertResult($method, $response, $options);

    return $output;
  }
}