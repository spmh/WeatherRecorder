<?php
/**
 * WeatherRecorder
 * 独立记录文章天气
 * 
 * @package WeatherRecorder
 * @author 蜡客小生
 * @version 5.25.0
 * @link https://www.lknc.vip/
 */

class WeatherRecorder_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 兼容新旧版本的数据库实例获取
     */
    private static function getDb()
    {
        // Typecho 1.3.0+ 使用命名空间类
        if (class_exists('\Typecho\Db')) {
            return \Typecho\Db::get();
        }
        // 旧版 Typecho 使用全局类名
        return Typecho_Db::get();
    }

    /**
     * 激活插件
     */
    public static function activate()
    {
        self::createWeatherTable();
        self::createLogDirectory();
        
        // 挂载 Hook（新旧版本通用）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'saveWeather');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishSave = array(__CLASS__, 'saveWeather');
        
        return _t('天气记录插件已激活');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('天气记录插件已禁用');
    }

    /**
     * 创建日志目录
     */
    private static function createLogDirectory()
    {
        $pluginDir = dirname(__FILE__);
        $logDir = $pluginDir . '/logs';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 测试目录可写性
        $testFile = $logDir . '/test.log';
        @file_put_contents($testFile, 'Test log directory writable');
        @unlink($testFile);
    }

    /**
     * 创建天气表（兼容新旧数据库类）
     */
    private static function createWeatherTable()
    {
        $db = self::getDb();
        $prefix = $db->getPrefix();
        $tableName = $prefix . 'weather';
        
        $tables = $db->fetchAll('SHOW TABLES LIKE "' . $tableName . '"');
        if (!empty($tables)) {
            return;
        }
        
        $sql = "CREATE TABLE `{$tableName}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `cid` int(11) NOT NULL COMMENT '文章ID',
            `weather_text` varchar(255) NOT NULL COMMENT '天气文本',
            `city` varchar(50) NOT NULL COMMENT '城市',
            `temperature` int(11) NOT NULL COMMENT '温度',
            `weather_code` varchar(10) NOT NULL COMMENT '天气代码',
            `created` int(10) NOT NULL COMMENT '记录时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `cid` (`cid`),
            KEY `city` (`city`),
            KEY `created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章天气记录表'";
        
        $db->query($sql);
    }

    /**
     * 插件配置面板（无需修改，兼容新旧 Widget 类）
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiSource = new Typecho_Widget_Helper_Form_Element_Radio(
            'api_source',
            array(
                'seniverse' => _t('心知天气'),
                'owm' => _t('OpenWeatherMap')
            ),
            'seniverse',
            _t('天气API来源')
        );
        $form->addInput($apiSource);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'api_key',
            null,
            '',
            _t('心知天气 API Key（私钥）'),
            _t('请填入心知天气控制台中的「私钥」')
        );
        $form->addInput($apiKey);

        $owmKey = new Typecho_Widget_Helper_Form_Element_Text(
            'owm_key',
            null,
            '',
            _t('OpenWeatherMap API Key'),
            _t('如果使用 OWM，请填写')
        );
        $form->addInput($owmKey);

        $defaultCity = new Typecho_Widget_Helper_Form_Element_Text(
            'default_city',
            null,
            '北京',
            _t('默认城市'),
            _t('作者未设置城市时使用，如：北京、上海、南宁')
        );
        $form->addInput($defaultCity);

        $debug = new Typecho_Widget_Helper_Form_Element_Radio(
            'debug',
            array(
                1 => _t('开启（调试用）'),
                0 => _t('关闭')
            ),
            0,
            _t('调试模式')
        );
        $form->addInput($debug);
    }

    /**
     * 个人配置
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        $city = new Typecho_Widget_Helper_Form_Element_Text(
            'weather_city',
            null,
            '',
            _t('天气城市'),
            _t('留空则使用站点默认城市')
        );
        $form->addInput($city);
    }

    /**
     * 日志记录（保存到插件目录下的 logs/weather.log）
     */
    private static function log($message)
    {
        $cfg = Typecho_Widget::widget('Widget_Options')->plugin('WeatherRecorder');
        if (!intval($cfg->debug)) {
            return;
        }
        
        $pluginDir = dirname(__FILE__);
        $logDir = $pluginDir . '/logs';
        $logFile = $logDir . '/weather.log';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * 保存/更新天气记录（兼容新旧版本）
     */
    public static function saveWeather($contents, $edit)
    {
        self::log("===== 开始保存/更新天气 =====");
        
        $cid = self::getCid($contents, $edit);
        if (!$cid) {
            self::log("无法获取文章ID，退出");
            return;
        }
        
        self::log("文章CID: " . $cid);
        
        // 获取配置（兼容新旧 Widget 类）
        $cfg = Typecho_Widget::widget('Widget_Options')->plugin('WeatherRecorder');
        
        $city = self::getCity($cid);
        $weatherData = self::getWeatherData($city, $cfg);
        if (!$weatherData) {
            self::log("获取天气数据失败");
            return;
        }
        
        $db = self::getDb();
        $exists = $db->fetchRow($db->select()->from('table.weather')->where('cid = ?', $cid));
        
        try {
            if ($exists) {
                self::log("发现现有天气记录，执行更新操作");
                $update = $db->update('table.weather')
                    ->rows(array(
                        'weather_text' => $weatherData['text'],
                        'city' => $city,
                        'temperature' => $weatherData['temperature'],
                        'weather_code' => $weatherData['code'],
                        'created' => time()
                    ))
                    ->where('cid = ?', $cid);
                $db->query($update);
                self::log("天气记录已更新，CID: " . $cid);
            } else {
                self::log("未发现现有天气记录，执行插入操作");
                $insert = $db->insert('table.weather')->rows(array(
                    'cid' => $cid,
                    'weather_text' => $weatherData['text'],
                    'city' => $city,
                    'temperature' => $weatherData['temperature'],
                    'weather_code' => $weatherData['code'],
                    'created' => time()
                ));
                $db->query($insert);
                self::log("天气记录已插入，CID: " . $cid);
            }
        } catch (Exception $e) {
            self::log("保存天气记录失败: " . $e->getMessage());
        }
        
        self::log("===== 保存/更新天气完成 =====");
    }

    /**
     * 获取文章CID（兼容新旧数据结构）
     */
    private static function getCid($contents, $edit)
    {
        $cid = 0;
        if (is_object($edit) && isset($edit->cid)) {
            $cid = $edit->cid;
        } elseif (is_array($contents) && isset($contents['cid'])) {
            $cid = $contents['cid'];
        }
        return $cid;
    }

    /**
     * 获取城市（兼容新旧数据库类）
     */
    private static function getCity($cid)
    {
        $cfg = Typecho_Widget::widget('Widget_Options')->plugin('WeatherRecorder');
        $defaultCity = $cfg->default_city ?: '北京';
        
        $db = self::getDb();
        $post = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $cid));
        if (!$post || !isset($post['authorId'])) {
            self::log("无法获取文章作者ID，使用默认城市: " . $defaultCity);
            return $defaultCity;
        }
        
        $authorId = $post['authorId'];
        try {
            $author = Typecho_Widget::widget('Widget_Users_Author', array('uid' => $authorId));
            if ($author && $author->weather_city) {
                self::log("使用作者城市: " . $author->weather_city);
                return $author->weather_city;
            }
        } catch (Exception $e) {
            self::log("获取作者城市失败: " . $e->getMessage());
        }
        
        self::log("使用默认城市: " . $defaultCity);
        return $defaultCity;
    }

    /**
     * 获取天气数据（兼容两种 API）
     */
    private static function getWeatherData($city, $cfg)
    {
        if ($cfg->api_source === 'seniverse') {
            return self::fetchFromSeniverse($city, $cfg->api_key);
        } elseif ($cfg->api_source === 'owm') {
            return self::fetchFromOWM($city, $cfg->owm_key);
        }
        return null;
    }

    /**
     * 心知天气 API 调用
     */
    private static function fetchFromSeniverse($city, $key)
    {
        if (empty($key)) {
            self::log("心知天气API Key未配置");
            return null;
        }
        
        $url = sprintf(
            'https://api.seniverse.com/v3/weather/now.json?key=%s&location=%s&language=zh-Hans&unit=c',
            urlencode($key),
            urlencode($city)
        );
        
        self::log("请求心知天气: " . preg_replace('/key=[^&]+/', 'key=***', $url));
        
        $response = self::httpRequest($url);
        if (!$response) {
            self::log("HTTP请求失败");
            return null;
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log("JSON解析失败: " . json_last_error_msg());
            return null;
        }
        
        if (!isset($data['results'][0]['now'])) {
            self::log("响应中无有效天气数据: " . substr($response, 0, 300));
            return null;
        }
        
        $now = $data['results'][0]['now'];
        $icon = self::getWeatherIcon($now['code']);
        
        return array(
            'text' => sprintf('%s 天气：%s，%s℃', $icon, $now['text'], $now['temperature']),
            'temperature' => intval($now['temperature']),
            'code' => $now['code']
        );
    }

    /**
     * OpenWeatherMap API 调用
     */
    private static function fetchFromOWM($city, $key)
    {
        if (empty($key)) {
            self::log("OpenWeatherMap API Key未配置");
            return null;
        }
        
        $url = sprintf(
            'https://api.openweathermap.org/data/2.5/weather?q=%s&appid=%s&units=metric&lang=zh_cn',
            urlencode($city),
            urlencode($key)
        );
        
        self::log("请求 OWM: " . $url);
        
        $response = self::httpRequest($url);
        if (!$response) {
            self::log("HTTP请求失败");
            return null;
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log("JSON解析失败: " . json_last_error_msg());
            return null;
        }
        
        if (!isset($data['main']['temp'])) {
            self::log("OWM响应中无有效数据: " . substr($response, 0, 300));
            return null;
        }
        
        $temp = round($data['main']['temp']);
        $desc = isset($data['weather'][0]['description']) ? $data['weather'][0]['description'] : '未知';
        $code = isset($data['weather'][0]['id']) ? strval($data['weather'][0]['id']) : '0';
        $icon = self::getOWMIcon($code);
        
        return array(
            'text' => sprintf('%s 天气：%s，%s℃', $icon, $desc, $temp),
            'temperature' => $temp,
            'code' => $code
        );
    }

    /**
     * HTTP 请求（兼容 curl/file_get_contents）
     */
    private static function httpRequest($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Typecho WeatherRecorder)',
            ));
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($errno) {
                self::log("cURL错误: " . $errno . " - " . $error);
                return null;
            }
            return $response;
        }
        
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; Typecho WeatherRecorder)'
            )
        ));
        return @file_get_contents($url, false, $context);
    }

    /**
     * 天气图标映射（心知天气）
     */
    private static function getWeatherIcon($code)
    {
        $code = strval($code);
        $iconMap = array(
            '0' => '☀️', '1' => '🌤️', '2' => '⛅', '3' => '🌦️',
            '4' => '🌧️', '5' => '🌧️', '6' => '🌧️', '7' => '🌧️',
            '8' => '❄️', '9' => '🌫️', '10' => '🌪️', '11' => '🌥️',
            '12' => '☁️', '13' => '🌬️', '14' => '🌦️', '15' => '🌧️',
            '16' => '🌧️', '17' => '🌨️', '18' => '❄️', '19' => '❄️',
            '20' => '❄️', '53' => '🌫️', '99' => '🌈',
        );
        return isset($iconMap[$code]) ? $iconMap[$code] : '🌈';
    }

    /**
     * 天气图标映射（OpenWeatherMap）
     */
    private static function getOWMIcon($id)
    {
        $id = intval($id);
        if ($id === 800) return '☀️';
        if ($id >= 801 && $id <= 803) return '🌤️';
        if ($id === 804) return '☁️';
        if ($id >= 500 && $id <= 531) return '🌧️';
        if ($id >= 600 && $id <= 622) return '❄️';
        if ($id >= 701 && $id <= 781) return '🌫️';
        if ($id >= 200 && $id <= 232) return '🌩️';
        return '🌈';
    }
}