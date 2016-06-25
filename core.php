<?php
namespace Azalea;

if (!defined('\AZALEA_ROOT')) {
  die('AZALEA_ROOT constant is not defined');
}
if (defined('\AZALEA_ENV')) {
  define(__NAMESPACE__ . '\ENV', \AZALEA_ENV);
} else {
  define(__NAMESPACE__ . '\ENV', 'WEB');
}
define(__NAMESPACE__ . '\VERSION', '1.0.0');
define(__NAMESPACE__ . '\REQUEST_TIME', isset($_SERVER['REQUEST_TIME']) ? $_SERVER['REQUEST_TIME'] : time());
define(__NAMESPACE__ . '\E404', new E404Exception());
define(__NAMESPACE__ . '\E500', new E500Exception());

final class Bootstrap
{
  private static $_baseUri;
  private static $_uri;
  private static $_route = [
    'folder'     => null,
    'controller' => 'default',
    'action'     => 'index',
    'arguments'  => [],
  ];
  private static $_instances = [];

  public static function getBaseUri()
  {
    return self::$_baseUri;
  }

  public static function getUri()
  {
    return self::$_uri;
  }

  public static function getRequestUri()
  {
    return isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : url(self::$_uri);
  }

  public static function getRoute()
  {
    return self::$_route;
  }

  public static function init($configFile)
  {
    timer();
    ob_start();
    // 读取 configFile
    $config = Config::load($configFile);
    // 打开错误提示
    if ($config['debug']) {
      error_reporting(E_ALL);
      ini_set('display_errors', true);
    }
    // 设置时区
    date_default_timezone_set($config['timezone']);
    // 获取路径
    self::$_baseUri = (isset($_SERVER['SCRIPT_NAME']) ?
        trim(dirname($_SERVER['SCRIPT_NAME']), '\\/') : '') . '/';
    self::$_uri = isset($_SERVER['PATH_INFO']) ?
        trim(preg_replace('/\/{2,}/', '/', $_SERVER['PATH_INFO']), '/') : '';
    // 设置会话
    session_name($config['session']['name']);
    session_set_cookie_params($config['session']['lifetime'],
        isset($config['session']['path']) ? $config['session']['path'] : self::$_baseUri,
        $config['session']['domain']);
    return new self;
  }

  public function run()
  {
    try {
      // 开启会话
      session_start();
      // 路由分析开始
      $folder = &self::$_route['folder'];
      $controller = &self::$_route['controller'];
      $action = &self::$_route['action'];
      $arguments = &self::$_route['arguments'];
      $uri = self::$_uri;
      // 静态路由配置
      $staticRouters = Config::get('router');
      if ($staticRouters) {
        $path = $uri;
        $pos = strlen($path);
        do {
          $key = strtolower($path);
          if (array_key_exists($key, $staticRouters)) {
            $uri = trim(preg_replace('/\/{2,}/', '/',
                strval($staticRouters[$key])), '/') . substr($uri, $pos);
            break;
          }
          $pos = strrpos($path, '/');
          if ($pos) {
            $path = substr($path, 0, $pos);
          }
        } while ($pos);
        unset($path, $pos);
      }
      // 路径
      $paths = ($uri == '') ? [] : explode('/', $uri);
      $pathConfig = Config::get('path');
      $controllerPath = \AZALEA_ROOT . '/' .
          ($pathConfig['basepath'] != '' ? ($pathConfig['basepath'] . '/') : '') .
          $pathConfig['controllers'];
      // 是否存在 folder
      if (isset($paths[0]) && is_dir($controllerPath . '/' . strtolower($paths[0]))) {
        $folder = strtolower(array_shift($paths));
        $controllerPath .= '/' . $folder;
      }
      // 是否存在 controller
      if (isset($paths[0])) {
        $controller = strtolower(array_shift($paths));
      }
      $controllerFile = $controllerPath . '/' . $controller . '.php';
      if (!is_file($controllerFile)) {
        throw new E404Exception('Controller file not found.');
      }
      // 加载 controller
      require $controllerFile;
      $controllerClass = (isset($folder) ? (ucfirst($folder)) : '') .
          ucfirst($controller) . 'Controller';
      if (!class_exists($controllerClass, false) ||
          !is_subclass_of($controllerClass, __NAMESPACE__ . '\Controller')) {
        throw new E404Exception('Controller class not found.');
      }
      // 动态路由配置
      if (method_exists($controllerClass, '__router')) {
        $routers = call_user_func([ $controllerClass, '__router' ], $paths);
        if ($routers === E_404) {
          throw new E404Exception('Router is invalid.');
        } else if (is_array($routers)) {
          foreach ($routers as $key => $value) {
            self::$_route[$key] = $value;
          }
        }
        unset($routers);
      } else {
        if (isset($paths[0])) {
          $action = strtolower(array_shift($paths));
        }
        if ($action[0] == '_') {
          throw new E404Exception('Action is invalid.');
        }
        // 参数
        $arguments = $paths;
      }
      // 是否存在 action
      $actionMethod = $action . (ENV == 'WEB' ? 'Action' : ENV);
      if (!method_exists($controllerClass, $actionMethod)) {
        throw new E404Exception('Action method not found.');
      }
      $result = $this->_dispatch();
      $this->_process($result);
    } catch (\Exception $e) {
      $this->_errorDispatch($e);
    }
  }

  private function _dispatch($route = null)
  {
    if (!isset($route)) {
      $route = self::$_route;
    }
    // 检查路由
    $controllerClass = (isset($route['folder']) ?
        (ucfirst($route['folder'])) : '') . ucfirst($route['controller']) . 'Controller';
    if (!class_exists($controllerClass, false)) {
      $pathConfig = Config::get('path');
      $controllerPath = \AZALEA_ROOT . '/' .
          ($pathConfig['basepath'] != '' ? ($pathConfig['basepath'] . '/') : '') .
          $pathConfig['controllers'];
      if (isset($route['folder'])) {
        $controllerPath .= '/' . $route['folder'] ;
      }
      $controllerFile = $controllerPath . '/' . $route['controller'] . '.php';
      if (!is_file($controllerFile)) {
        throw new E404Exception('Controller file not found.');
      }
      require $controllerFile;
      if (!class_exists($controllerClass, false) ||
          !is_subclass_of($controllerClass, __NAMESPACE__ . '\Controller')) {
        throw new E404Exception('Controller class not found.');
      }
    }
    if (!isset(self::$_instances[$controllerClass])) {
      self::$_instances[$controllerClass] = new $controllerClass();
    }
    $controllerInstance = self::$_instances[$controllerClass];
    $actionMethod = $route['action'] . (ENV == 'WEB' ? 'Action' : ENV);
    if (!method_exists($controllerClass, $actionMethod)) {
      throw new E404Exception('Action method not found.');
    }
    // 执行 action
    return call_user_func_array([ $controllerInstance, $actionMethod ], $route['arguments']);
  }

  private function _errorDispatch(\Exception $e)
  {
    ob_clean();
    if (ENV == 'WEB') {
      try {
        $result = $this->_dispatch([
          'controller' => 'error',
          'action' => 'error',
          'arguments' => [ 'exception' => $e ],
        ]);
        $this->_process($result);
      } catch (Exception $ignoreEx) {
        die($e->getMessage());
      }
    } else {
      die($e->getMessage() . PHP_EOL);
    }
  }

  private function _process($result)
  {
    if (isset($result)) {
      if ($result instanceof Exception) {
        throw $result;
      } else if (is_array($result) || is_object($result)) {
        echo json_encode($result);
      } else {
        echo strval($result);
      }
    }
    echo ob_get_clean();
  }
}

class Controller
{
  protected $req, $res;
  private $_view = null;

  final public function __construct()
  {
    $this->req = Request::getInstance();
    $this->res = Response::getInstance();
    $this->__init();
  }

  protected function __init() {}

  protected function getService()
  {
    // TODO get service model
  }

  protected function getModel($name)
  {
    return getModel($name);
  }

  protected function getView()
  {
    if (!isset($this->_view)) {
      $this->_view = new View();
    }
    return $this->_view;
  }

  protected function getSession()
  {
    return Session::getInstance();
  }
}

final class Request
{
  private function __construct() {}

  public static function getInstance()
  {
    static $instance = null;
    if (!$instance) {
      $instance = new self();
    }
    return $instance;
  }

  public function getUri()
  {
    return Bootstrap::getUri();
  }

  public function getRequestUri()
  {
    return Bootstrap::getRequestUri();
  }

  public function getBaseUri()
  {
    return Bootstrap::getBaseUri();
  }

  public function isPost()
  {
    return $_SERVER['REQUEST_METHOD'] == 'POST';
  }

  public function isAjax()
  {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
  }

  public function getQuery($field = null, $default = null)
  {
    if (!isset($field)) {
      return $_GET;
    }
    return key_exists($_GET[$field]) ? $_GET[$field] : $default;
  }

  public function getPost($field = null, $default = null)
  {
    if (!isset($field)) {
      return $_POST;
    }
    return key_exists($_POST[$field]) ? $_POST[$field] : $default;
  }
}

final class Response
{
  private function __construct() {}

  public static function getInstance()
  {
    static $instance = null;
    if (!$instance) {
      $instance = new self();
    }
    return $instance;
  }

  public function gotoUrl($url)
  {
  }

  public function gotoRoute($route)
  {
  }

  public function getBody()
  {
    return ob_get_contents();
  }

  public function setBody($body)
  {
    ob_clean();
    echo $body;
  }
}

final class Session
{
  private function __construct() {}

  public static function getInstance()
  {
    static $instance = null;
    if (!$instance) {
      $instance = new self();
    }
    return $instance;
  }

  public function get($key = null, $default = null)
  {
    if (!isset($key)) {
      return $_SESSION;
    }
    return key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
  }

  public function set($key, $value)
  {
    $_SESSION[$key] = $value;
  }

  public function clear()
  {
    $_SESSION = [];
  }
}

abstract class Model
{
  public function __construct()
  {
    $this->__init();
  }

  protected function __init() {}

  public function getModel($name)
  {
    return getModel($name);
  }
}

final class View
{
  private static $_tplPath = '';
  private $_data = [];

  public function __construct()
  {
    $pathConfig = Config::get('path');
    $theme = Config::get('theme');
    self::$_tplPath = \AZALEA_ROOT . '/' .
        ($pathConfig['basepath'] != '' ? ($pathConfig['basepath'] . '/') : '') .
        $pathConfig['views'] .
        ((isset($theme) && $theme != '') ? ('/' . $theme) : '');
    $this->assign([
      'tpldir' => url($pathConfig['static'] . '/' .
          ((isset($theme) && $theme != '') ? ('/' . $theme) : '')),
      'debug' => Config::get('debug'),
    ]);
  }

  public function render($tpl, $vars = null)
  {
    $filename = self::$_tplPath . '/' . $tpl . '.phtml';
    if (!is_file($filename)) {
      throw new Exception('View file "' . $tpl . '.phtml" not found.');
    }
    if (isset($vars) && is_array($vars)) {
      $this->assign($vars);
    }
    extract($this->_data, EXTR_OVERWRITE);
    ob_start();
    include $filename;
    return ob_get_clean();
  }

  public function assign($key, $value = null)
  {
    if (is_array($key)) {
      foreach ($key as $k => $value) {
        $this->_data[$k] = $value;
      }
    } else {
      $this->_data[$key] = $value;
    }
    return $this;
  }

  public function plain($text)
  {
    return htmlspecialchars($text, ENT_QUOTES);
  }
}

final class Config
{
  private static $_config = [];

  public static function load($filename)
  {
    if (is_readable($filename)) {
      $config = parse_ini_file($filename, true);
      $config += [
        'debug' => false,
        'timezone' => 'PRC',
        'theme' => null,
        'session' => [],
        'path' => [],
        'service' => [],
        'router' => [],
      ];
      $config['session'] += [
        'name' => 'sid',
        'lifetime' => 0,
        'path' => null,
        'domain' => null,
      ];
      $config['path'] += [
        'basepath' => null,
        'controllers' => 'controllers',
        'models' => 'models',
        'views' => 'views',
        'static' => 'static',
      ];
      $config['service'] += [
        'timeout' => 10,
        'connecttimeout' => 2,
        'retry' => 1,
      ];
      self::$_config = $config;
    } else {
      die('Config file not found');
    }
    return self::$_config;
  }

  public static function get($key = null, $default = null)
  {
    if (!isset($key)) {
      return self::$_config;
    }
    return key_exists($key, self::$_config) ? self::$_config[$key] : $default;
  }

  public static function set($key, $value)
  {
    self::$_config[$key] = $value;
  }
}

class Exception extends \Exception
{
  const E_NOTICE = 1;
  const E_WARNING = 2;
  const E_ERROR = 4;

  private $_level;
  private $_context = null;

  public function __construct($message, $level = self::E_WARNING, $code = 0, $context = null)
  {
    parent::__construct($message, $code);
    $this->_level = $level;
    $this->_context = $context;
  }

  public function getLevel()
  {
    return $this->_level;
  }

  public function getContext()
  {
    return $this->_context;
  }
}

final class E404Exception extends Exception
{
  private $_uri;
  private $_route;

  public function __construct($message = '', $context = null)
  {
    parent::__construct($message, parent::E_WARNING, 404, $context);
    $this->_uri = Bootstrap::getUri();
    $this->_route = Bootstrap::getRoute();
    if (ENV == 'WEB') {
      header('HTTP/1.1 404 Not Found');
    }
  }

  public function getUri()
  {
    return $this->_uri;
  }

  public function getRoute()
  {
    return $this->_route;
  }
}

final class E500Exception extends Exception
{
  public function __construct($message = '', $code = 500, $context = null)
  {
    parent::__construct($message, parent::E_ERROR, $code, $context);
    if (ENV == 'WEB') {
      header('HTTP/1.1 500 Internal Server Error');
    }
  }
}

function timer()
{
  static $timer = null;
  if (!isset($timer)) {
    $timer = microtime(true);
    return 0;
  } else {
    $startTimer = $timer;
    $timer = microtime(true);
    return $timer - $startTimer;
  }
}

function url($path, $includeDomain = false)
{
  static $domainUrl = null;
  if (!isset($domainUrl)) {
    $domainUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http') .
        '://' . strtolower($_SERVER['HTTP_HOST']);
  }
  if (!strcasecmp(substr($path, 0, 7), 'http://') || !strcasecmp(substr($path, 0, 8), 'https://')) {
    return $path;
  }
  return ($includeDomain ? $domainUrl : '') . Bootstrap::getBaseUri() . ltrim($path, '/');
}

function randomString($len, $type = null) {}

function getModel($name)
{
  static $list = [];
  $name = strtolower($name);
  if (!isset($list[$name])) {
    $pathConfig = Config::get('path');
    $modelFile =\AZALEA_ROOT . '/' .
        ($pathConfig['basepath'] != '' ? ($pathConfig['basepath'] . '/') : '') .
        $pathConfig['models'] . '/' . $name . '.php';
    if (!is_file($modelFile)) {
      throw new Exception('Model file not found.');
    }
    require($modelFile);
    $modelClass = ucfirst($name) . 'Model';
    if (!class_exists($modelClass, false) || !is_subclass_of($modelClass, __NAMESPACE__ . '\Model')) {
      throw new Exception('Model class not found.');
    }
    $list[$name] = new $modelClass();
  }
  return $list[$name];
}
