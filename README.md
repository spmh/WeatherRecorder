# 【Typecho插件】独立记录文章天气：WeatherRecorder

 - 插件版本：5.25.0
 - 插件作者：蜡客小生
 - 插件下载：https://www.lknc.vip/8777.html

## 插件介绍：

新建或修改文章自动记录当天天气预报数据。

## 使用和配置：

> 上传插件文件：

 - 下载插件并解压，将“WeatherRecorder”文件上传至目录“/usr/plugins/”中。

> 启用插件：

 - 登录 Typecho 后台
 - 进入「控制台」→「插件管理」
 - 找到 WeatherRecorder 并点击「启用」

> 配置插件：

 - 点击插件右侧的「设置」
 - 选择 API 来源：“心知天气”或者“OpenWeatherMap”
 - 填入API Key私钥。
 - 设置默认城市（常驻城市，如：北京）。
 - 根据需要开启调试模式（调试日志：保存在插件目录下的 logs/weather.log）。

> 设置作者城市：

 - 作者非常驻城市（即作者所在当前的城市）
 - 进入「控制台」→「个人设置」
 - 找到「天气城市」并填写（如：上海）

注：若作者不设置城市，即调用插件中所配置的默认城市。

 - 保存设置

## 前端输出：
```
<?php
// 兼容新旧版本的数据库实例获取
function getFrontendDb() {
    if (class_exists('\Typecho\Db')) {
        return \Typecho\Db::get();
    }
    return Typecho_Db::get();
}

// 获取天气数据
$weather = null;
if ($this->cid) {
    $db = getFrontendDb();
    $weather = $db->fetchRow($db->select()->from('table.weather')->where('cid = ?', $this->cid));
}
?>

<div class="post-weather" style="background:#f8f9fa;padding:12px 16px;border-left:4px solid #4da3ff;margin:16px 0;font-size:14px;color:#555;">
    <?php if ($weather && $weather['weather_text']): ?>
        <?php echo htmlspecialchars($weather['weather_text']); ?>
    <?php else: ?>
        🌑 天气：未记录
    <?php endif; ?>
</div>
```
