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


<h3 name="nodejs">Node.js (node-azalea)</h3>

Node.js 服务端 SDK 请查阅 https://www.npmjs.com/package/node-azalea

Node.js 服务端github地址 https://github.com/hdwong/node-beauty
