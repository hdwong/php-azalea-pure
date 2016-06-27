# php-azalea-pure
php-azalea-pure (以下简称 azalea) 是基于php7语法和Node.js服务的MVC开发框架。

## 目录
- [搭建](#build)
- [核心文件](#core)
  - [Config类](#Config) 
  - [初始化启动类](#Bootstrap)
    - [初始化](#init) 
    - [路由分析](#run)
    - [执行](#_dispatch)
    - [执行报错处理](#_errorDispatch)
    - [处理输出](#_process)
  - [Controler超类](#Controler)
  - [Model超类](#Model)
  - [View类](#View)
  - [请求类(Request)](#Request)
  - [响应类(Response)](#Response)
  - [会话类(Session)](#Session)
  - [Exception类](#Exception)
  - [E404Exception类](#E404Exception)
  - [E500Exception类](#E500Exception)
  - [timer函数](#timer)
  - [url函数](#url)
  - [randomString函数](#randomString)
  - [获取Model实例函数](#getModel)
- [Node.js 服务端SDK](#nodejs)
  
<h2 name="build">搭建</h2>

azalea需要准备一个ini格式的配置文件，需要自己配置basepath，url，token之外，其它都有默认值
``` ini
＃ 是否开启debug模式，默认为false
debug = on 

# 时区，默认PRC
timezone = PRC

# 主题，默认null 
theme ＝ null

# 会话，默认配置
[session]
  name = sid
  lifttime = 0
  path = null
  domain = null

[path]
  # 主程序路径，需自配
  basepath = sys 
  
  # 控制器的文件夹,默认配置basepath路径下的子文件夹controllers
  controllers ＝ controllers
  
  # 模型的文件夹,默认配置basepath路径下的子文件夹models
  models ＝ models
  
  # 视图的文件夹,默认配置basepath路径下的子文件夹views
  views ＝ views
  
  # 静态文件的文件夹,默认配置basepath路径下的子文件夹static
  static ＝ static

[service]
  # 服务地址，需自配
  url = http://127.0.0.1:1108/ 
  
  # 服务通信token，需自配
  token = abcd1234 
  
  # 服务请求超时时间，默认10
  timeout ＝ 10
  
  # 服务连接超时时间，默认2
  connecttimeout ＝ 2
  
  # 服务连接失败重试次数，默认1
  retry ＝ 1
  
# 配置静态路由，默认为空  
[router]

folder/controller/action = example.html

```

本人程序目录结构，做修改时，请修改相应的配置

<pre>

+-----php-azalea-pure 
  +----etc  
    +---config.php  
  +----public 
    +---index.php 
  +----sys 
    +---controlers 
      +--default.php 
    +---models 
    +---views 
    +---satic 
  +----core.php 
  
</pre>

程序入口文件index.php

```php

<?php
  define('AZALEA_ROOT', dirname(__DIR__));
  require_once '../core.php';// 引入核心文件，路径要配好
  // 初始化，传入正确的配置文件路径，启动
  Azalea\Bootstrap::init(AZALEA_ROOT . '/etc/config.ini)->run();
  
```

访问主域名，程序默认访问Controllers文件夹下的default.php文件，DefaultController类的indexAction方法
```php

<?php
  class DefaultController extends Azalea\Controller
  {
    public function indexAction()
    {
      var_dump('Hello  World！');exit;
    }
  }
  
```

<h1 name="core">核心文件core.php</h1>

core.php 程序的核心文件，加载配置，初始化程序数据，路由等功能实现。

<h2 name="config">Config类</h1>

``Config::load($filename)``   程序初始化时，加载配置文件（etc/config.php）参数，设定默认值，@param $filename 配置文件，不可读时，结束程序，抛出：Config file not found。

``Config::get($key = null, $default = null)`` 根据$key值获取配置，没有以$key为键的配置时，返回$default值。

``Config::set($key, $value)`` 动态设置以$key为键，以$value为值的配置。

<h2 name="Bootstrap">初始化启动类</h2>

<h3 name="init">``Bootstrap::init($configFile = null)``</h3>
程序初始化，加载配置，设置报错，设置会话，设置时区，获取uri。

<h3 name="run">``Bootstrap::run()``</h3>
开启会话，路由分析，加载相应Controller方法，调用<a name="dispatch">执行控制器</a>，<a name="process">处理程序返回结果</a>。

<h4 name="router">路由规则</h4>
  程序有三种路由规则，优先级从低到高分别是：程序路由，静态路由，动态路由。
  程序路由：程序本来的路由规则，比如访问程序url：http://example.com/folder/example/acn/param1/param2；
    默认访问的是：sys/controllers/folder/controller.php 文件中的FolderExampleController类的acnAction方法，param1, param2是参数;
    folder：sys/controllers/folder，控制器文件夹下的子文件夹，只允许一层子文件夹，可选。
    example：控制器文件名，类的命名规则是，有folder情况下，就FolderExampleController, 否则, ExampleController; 控制器类必须继承超类 Azalea\Controller; 以_开始命名的方法都是不可访问的。
    acn：调用的方法名，默认是以Action为后缀，可配置常量\AZALEA_ENV改变后缀。
    param1、param2：传给方法的参数，如acnAction(param1，param2)。

  静态路由：静态路由是在<a name="config">config.php</a>配置的router。

  动态路由：动态路由是在当前访问的控制器下的__router方法返回的路由，即带有以下键的数组：folder(可选), controller, action;

<h3 name="dispatch">``Bootstrap::dispatch($route = null)``</h3>
根据路由数据执行相应的控制器方法，并返回执行结果。参数$route可选，当为假值时，默认为<a name="Bootstrap">初始化启动类</a>的$__route成员。

<h3 name="_errorDispatch">``Bootstrap::_errorDispatch(\Exception $e)``</h3>
处理由<a name="dispatch">执行控制器</a>产生的错误。

<h3 name="_process">``Bootstrap::_process($result)``</h3>
处理由<a name="dispatch">执行控制器</a>返回值并输出缓冲区内容。
$result：为数组时，输出json; 等于常量E404时，抛E404Exception()错误；等于常量E500时，抛E500Exception()错误；否则，输出字符串； 

<h2 name="Controller">Controller超类</h2>
控制器超类，所有控制器都要继承该超类。

``Controller::__construct($name)`` 构造函数，实例化控制器类时，将当前类名$name赋值给成员变量_name，并执行当前控制器类的__init()方法。

``Controller::__init()`` 初始化方法，子控制器类重写后，实例化时直接调用执行。

``Controller::__get()`` php魔术方法，提供id，req，res属性的获取。$this->id：返回当前类名；$this->req：返回<a name="Requst">请求类</a>的实例化对象；$this->res：返回<a name="Response">响应类</a>的实例化对象。

``Controller::getService()`` 获取服务。

``Controller::getModel($name, ...$args)`` 根据模型名$name获取<a name="getModel">模型</a>实例对象，并传参。

``Controller::getView()`` 获取<a name="View">视图</a>实例对象。

``Controller::getSession()`` 获取<a name="Session">会话</a>实例对象。

<h2 name="Model">Model超类</h2>

<h2 name="View">View类</h2>

<h2 name="Request">请求类(Request)</h2>

<h2 name="Response">响应类(Response)</h2>

<h2 name="Session">会话类(Session)</h2>

<h2 name="Exception">Exception类</h2>

<h2 name="E404Exception">E404Exception类</h2>

<h2 name="E500Exception">E500Exception类</h2>

<h2 name="timer">timer函数</h2>

<h2 name="url">url函数</h2>

<h2 name="randomString">randomString函数</h2>

<h2 name="getModel">getModel函数</h2>

<h2 name="getModel">getModel函数</h2>

<h3 name="nodejs">Node.js (node-azalea)</h3>

Node.js 服务端 SDK 请查阅 https://www.npmjs.com/package/node-azalea

Node.js 服务端github地址 https://github.com/hdwong/node-beauty
