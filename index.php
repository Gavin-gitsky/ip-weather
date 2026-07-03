<?php
// 加载外部配置文件
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    die("请复制 config.example.php 并重命名为 config.php 填写密钥");
}

class App
{
    // 从配置读取腾讯密钥
    private $txkey;
    // 防盗链开关 1=允许直接访问 0=仅允许带refer访问
    public $referopen;
    // 缓存时长 30分钟=1800秒
    private $cache_ttl = 1800;
    // 程序根目录
    private $path = __DIR__ . "/";
    // 天气缓存目录（按城市adcode缓存）
    private $cache_dir = __DIR__ . "/cache/weather/";
    // IP定位缓存目录
    private $ip_cache_dir = __DIR__ . "/cache/ip/";

    public $browser = null;
    public $os = null;
    public $ip = null;
    public $adcode = null;
    public $weather = null;
    public $drawIm = null;
    public $weekarray = array("日", "一", "二", "三", "四", "五", "六");
    public $city = "";
    public $province = "";
    public $location_str = "";
    // 自定义站点标题
    public $site_name = "";
    // 鉴权域名
    private $site_referer = "";

    public function __construct($txkey, $referopen, $site_name, $site_referer)
    {
        $this->txkey = $txkey;
        $this->referopen = $referopen;
        $this->site_name = $site_name;
        $this->site_referer = $site_referer;
        // 自动创建缓存文件夹
        if (!is_dir($this->cache_dir)) mkdir($this->cache_dir, 0777, true);
        if (!is_dir($this->ip_cache_dir)) mkdir($this->ip_cache_dir, 0777, true);
    }

    public function init()
    {
        $this->browser = $this->getBrowser();
        $this->os = $this->getOs();
        // 处理CDN反向代理获取真实IP
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $list = explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $_SERVER['REMOTE_ADDR'] = end($list);
        }
        $this->ip = $_SERVER["REMOTE_ADDR"];
        $this->getAddress();
        $this->picInit()->setWeather()->setIcon()->setInfo();
        return $this;
    }

    // 统一素材加载函数，支持 png/jpg/webp
    private function loadImage(string $path)
    {
        if (!file_exists($path)) return false;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        // 直接传文件路径，不要file_get_contents读取二进制
        switch ($ext) {
            case 'webp':
                return imagecreatefromwebp($path);
            case 'jpg':
            case 'jpeg':
                return imagecreatefromjpeg($path);
            case 'png':
            default:
                return imagecreatefrompng($path);
        }
    }

    // 获取IP定位，带本地缓存
    public function getAddress()
    {
        $ip_md5 = md5($this->ip);
        $ip_cache_file = $this->ip_cache_dir . $ip_md5 . ".json";
        // 读取IP缓存
        if (file_exists($ip_cache_file) && (time() - filemtime($ip_cache_file) < $this->cache_ttl)) {
            $json = file_get_contents($ip_cache_file);
        } else {
            $url = "https://apis.map.qq.com/ws/location/v1/ip?key=" . $this->txkey . "&ip=" . $this->ip;
            $json = $this->curlGet($url);
            $res = json_decode($json, true);
            if ($res && $res['status'] == 0) {
                file_put_contents($ip_cache_file, $json);
            }
        }
        $res = json_decode($json, true);
        if ($res && $res['status'] == 0) {
            $info = $res['result']['ad_info'];
            $this->province = $info['province'];
            $this->city = $info['city'];
            $this->adcode = $info['adcode'];
            $this->location_str = $this->province . '-' . $this->city;
            if ($this->adcode) {
                $this->getWeather();
            }
        }
        return $this;
    }

    // 获取天气，按城市adcode缓存，同城共享
    public function getWeather()
    {
        $cache_file = $this->cache_dir . $this->adcode . ".json";
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < $this->cache_ttl)) {
            $json = file_get_contents($cache_file);
        } else {
            $tqurl = "https://apis.map.qq.com/ws/weather/v1/?key=" . $this->txkey . "&adcode=" . $this->adcode;
            $json = $this->curlGet($tqurl);
            $check = json_decode($json, true);
            if ($check && $check['status'] == 0) {
                file_put_contents($cache_file, $json);
            }
        }
        $weatherinfo = json_decode($json, true);
        if ($weatherinfo && $weatherinfo['status'] == 0 && isset($weatherinfo['result']['realtime'][0])) {
            $realtime = $weatherinfo['result']['realtime'][0];
            $infos = $realtime['infos'];
            $this->weather = [
                'weather' => $infos['weather'],
                'temp' => $infos['temperature'],
                'humidity' => $infos['humidity'],
                'winddirection' => $infos['wind_direction'],
                'windpower' => $infos['wind_power'],
                'reporttime' => date("H:i", strtotime($realtime['update_time']))
            ];
        }
        return $this;
    }

    // 绘制天气图标
    public function setWeather()
    {
        if (!$this->weather) return $this;
        $w_text = $this->weather['weather'];
        $icon_file = 'unknow.png';
        if (strpos($w_text, '雨') !== false) $icon_file = 'rain.png';
        elseif (strpos($w_text, '雪') !== false) $icon_file = 'snow.png';
        elseif (strpos($w_text, '霾') !== false) $icon_file = 'mai.png';
        elseif (strpos($w_text, '雾') !== false) $icon_file = 'wu.png';
        elseif (strpos($w_text, '晴') !== false) $icon_file = 'sunny.png';
        elseif (strpos($w_text, '云') !== false) $icon_file = 'dyun.png';
        elseif (strpos($w_text, '阴') !== false) $icon_file = 'yin.png';

        $icon_path = $this->path . 'icon/weather/' . $icon_file;
        $icon_im = $this->loadImage($icon_path);
        if ($icon_im) {
            imagecopy($this->drawIm, $icon_im, 65, 50, 0, 0, imagesx($icon_im), imagesy($icon_im));
            imagedestroy($icon_im);
        }
        $color = imagecolorallocate($this->drawIm, 0, 51, 78);
        $font = $this->path . "font/msyh.ttf";
        imagettftext($this->drawIm, 14, 0, 95, 145, $color, $font, $this->weather['weather']);
        imagettftext($this->drawIm, 13, 0, 160, 90, $color, $font, '温度:' . $this->weather['temp'] . '℃');
        imagettftext($this->drawIm, 13, 0, 255, 90, $color, $font, '湿度:' . $this->weather['humidity'] . '%RH');
        imagettftext($this->drawIm, 11, 0, 160, 115, $color, $font, '风向:' . $this->weather['winddirection']);
        imagettftext($this->drawIm, 11, 0, 255, 115, $color, $font, '风力:' . $this->weather['windpower']);
        imagettftext($this->drawIm, 11, 0, 160, 135, $color, $font, '更新时间:' . $this->weather['reporttime']);
        return $this;
    }

    // 侧边小图标渲染
    public function setIcon()
    {
        $icons = ['time' => [148, 180], 'local' => [148, 200], 'IP' => [148, 220]];
        if (!filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $icons['bro'] = [270, 220];
        }
        if (mb_strwidth($this->location_str, 'UTF-8') <= 18) {
            $icons['system'] = [270, 200];
        }
        foreach ($icons as $name => $pos) {
            $path = $this->path . "icon/ico/{$name}.png";
            $im = $this->loadImage($path);
            if ($im) {
                imagecopy($this->drawIm, $im, $pos[0], $pos[1], 0, 0, imagesx($im), imagesy($im));
                imagedestroy($im);
            }
        }
        return $this;
    }

    // 文字信息绘制
    public function setInfo()
    {
        $color = imagecolorallocate($this->drawIm, 0, 51, 78);
        $font = $this->path . "font/msyh.ttf";
        // 配置文件自定义标题
        imagettftext($this->drawIm, 16, 0, 66, 45, $color, $font, $this->site_name);
        imagettftext($this->drawIm, 10, 0, 167, 194, $color, $font, date('Y年n月j日') . " 星期" . $this->weekarray[date("w")]);
        imagettftext($this->drawIm, 10, 0, 165, 212, $color, $font, $this->location_str);
        imagettftext($this->drawIm, 10, 0, 165, 232, $color, $font, $this->ip);
        // DIY自定义标题参数
        $_diy = isset($_GET["diy"]) ? base64_decode($_GET["diy"]) : "";
        if (!empty($_diy)) {
            imagettftext($this->drawIm, 16, 0, 150, 45, $color, $font, $_diy);
        }
        if (mb_strwidth($this->location_str, 'UTF-8') <= 18) {
            imagettftext($this->drawIm, 10, 0, 288, 212, $color, $font, $this->os);
        }
        if (!filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            imagettftext($this->drawIm, 10, 0, 288, 232, $color, $font, $this->browser);
        }
        return $this;
    }

    // 背景图初始化，自动扫描bg前缀，支持webp/png/jpg
    public function picInit()
    {
        // 匹配 bg1、bg2 任意后缀 webp/png/jpg
        $bgFiles = glob($this->path . "img/bg*.{png,jpg,jpeg,webp}", GLOB_BRACE);
        if (empty($bgFiles)) {
            $this->drawIm = imagecreatetruecolor(400, 250);
        } else {
            shuffle($bgFiles);
            $dst_path = $bgFiles[0];
            $this->drawIm = $this->loadImage($dst_path);
            // 兜底画布
            if (!$this->drawIm) $this->drawIm = imagecreatetruecolor(400, 250);
        }
        return $this;
    }

    // 输出图片 默认GIF兼容全平台，注释内切换WebP输出
    public function suture()
    {
        // 方案1：输出GIF（推荐，兼容微信/QQ/老旧浏览器）
        header("Content-type: image/gif");
        imagegif($this->drawIm);

        // 方案2：输出WebP（体积更小，取消注释启用）
        // header("Content-type: image/webp");
        // imagewebp($this->drawIm, null, 85);

        imagedestroy($this->drawIm);
    }

    // CURL请求，携带域名Referer解决腾讯110鉴权报错
    private function curlGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_REFERER, $this->site_referer);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    // 完整浏览器识别
    public function getBrowser()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('#Edge/([0-9.]+)#i', $ua)) return "Edge";
        if (preg_match('#Firefox/([0-9.]+)#i', $ua)) return "Firefox";
        if (preg_match('#QQBrowser#i', $ua)) return "QQ浏览器";
        if (preg_match('#360Browser#i', $ua)) return "360浏览器";
        if (preg_match('#Chrome/([0-9.]+)#i', $ua)) return "Chrome";
        if (preg_match('#Safari/([0-9.]+)#i', $ua)) return "Safari";
        return "未知浏览器";
    }

    // 系统识别
    public function getOs()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('/HarmonyOS/i', $ua)) return "鸿蒙";
        if (preg_match('/Windows NT 10.0/i', $ua)) {
            $ch_v = $_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'] ?? '';
            if ($ch_v !== '') {
                $v = (int)trim($ch_v, '"');
                if ($v >= 13) return "Windows 11";
            }
            return "Windows 10";
        }
        if (preg_match('/iPhone|iPad|iOS/i', $ua)) return "iOS";
        if (preg_match('/Android/i', $ua)) return "Android";
        return "未知系统";
    }
}

// 实例化
$app = new App($tencent_map_key, $referopen, $site_name, $site_referer);

// 防盗链判断逻辑
if ($app->referopen == 0) {
    if (empty($_SERVER['HTTP_REFERER'])) {
        die("禁止直接访问图片地址，请从站点内嵌入使用");
    }
}
$app->init()->suture();