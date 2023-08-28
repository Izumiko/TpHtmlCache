<?php

namespace TypechoPlugin\TpHtmlCache;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Typecho文章静态化插件
 *
 * @package TpHtmlCache
 * @author huhaku
 * @version 1.2.0
 * @link https://ggdog.info
 */
class Plugin implements PluginInterface
{
    /**
     * 递归删除目录
     *
     * @access public
     * @param string $dir 目录路径
     * @return void
     */
    public static function recursiveDelete($dir): void
    {

        if ($handle = @opendir($dir)) {
            while (($file = readdir($handle)) !== false) {
                if (($file == ".") || ($file == "..")) {
                    continue;
                }
                if (is_dir($dir . '/' . $file)) {
                    self::recursiveDelete($dir . '/' . $file);
                } else {
                    unlink($dir . '/' . $file);
                }
            }
            @closedir($handle);
            rmdir($dir);
        }
    }

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @static
     * @access public
     * @throws Exception
     */
    public static function activate()
    {
        //页面首尾
        \Typecho\Plugin::factory('index.php')->begin = __CLASS__ . '::Start';
        \Typecho\Plugin::factory('index.php')->end = __CLASS__ . '::Ends';
        $dir = __DIR__ . "/cache/"; //缓存目录
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        } else {
            chmod($dir, 0775);
        }

        try {
            file_put_contents($dir . 'index.html', "Error 403");
        } catch (\Exception $e) {
            throw new Exception('缓存目录不可写');
        }

        return '插件安装成功';
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @throws Exception
     */
    public static function deactivate()
    {
        try {
            self::recursiveDelete(__DIR__ . "/cache/");
        } catch (\Exception $e) {
            throw new Exception('缓存目录删除失败');
        }
        return '插件卸载成功';
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form)
    {
        $allow_path = new Text('allow_path', NULL, '/archives/', _t('需要缓存的路径,英文逗号分隔,从前往后匹配'));
        $form->addInput($allow_path);
        $cache_time = new Text('cache_time', NULL, '86400', _t('缓存时间(秒),为0则禁用缓存'));
        $form->addInput($cache_time);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 缓存前置操作
     *
     * @access public
     * @return void
     */
    public static function Start(): void
    {
        $config = Options::alloc()->plugin('TpHtmlCache');

        if (User::alloc()->hasLogin()) {
            if (!is_writable(__DIR__ . "/cache/")) {
                echo '<span style="text-align: center;display: block;margin: auto;font-size: 1.5em;color:#ff0000">设置目录权限失败,cache目录似乎不可写</span>';
            }
            if (empty($config->allow_path)) {
                $options = Options::alloc();
                $config_url = trim($options->siteUrl, '/') . '/' . trim(__TYPECHO_ADMIN_DIR__, '/') . '/options-plugin.php?config=TpHtmlCache';
                echo '<span style="text-align: center;display: block;margin: auto;font-size: 1.5em;color:#1abc9c">你似乎还没有初始化缓存插件，<a href="' . $config_url . '">马上去设置</a></span>';
            }

            return; //已登录用户不缓存
        }

        //过期时间设置为0禁用缓存
        if ($config->cache_time == 0 || empty($config->cache_time)) return;

        if (!self::needCache($_SERVER["REQUEST_URI"])) return;

        $expire = ($config->cache_time == '') ? 86400 : $config->cache_time;
        $files = mb_substr(md5($_SERVER["REQUEST_URI"]), 0, 2);
        $file = __DIR__ . "/cache/" . $files . "/" . md5($_SERVER["REQUEST_URI"]) . ".html"; //文件路径
        $dir = __DIR__ . "/cache/" . $files . "/"; //缓存目录
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        }
        if (file_exists($file)) {
            $file_time = @filemtime($file);
            if (time() - $file_time < $expire) {
                echo file_get_contents($file); //直接输出缓存
                exit();
            }
        }
        ob_start(); //打开缓冲区
    }

    /**
     * 缓存后置操作
     *
     * @access public
     * @return void
     */
    public static function Ends(): void
    {
        $config = Options::alloc()->plugin('TpHtmlCache');
        if (empty($config->allow_path)) return;

        if (User::alloc()->hasLogin()) return;
        //过期时间设置为0禁用缓存
        if ($config->cache_time == 0 || empty($config->cache_time)) return;

        if (!self::needCache($_SERVER["REQUEST_URI"])) return;

        $files = mb_substr(md5($_SERVER["REQUEST_URI"]), 0, 2);
        $file = __DIR__ . "/cache/" . $files . "/" . md5($_SERVER["REQUEST_URI"]) . ".html"; //文件路径
        $html = ob_get_contents() . "<!--TpHtmlCache " . date("Y-m-d h:i:s") . "-->";
        file_put_contents($file, $html);
    }

    /**
     * 根据配置判断是否需要缓存
     *
     * @access public
     * @param string $path 路径信息
     * @return bool
     */
    public static function needCache(string $path): bool
    {
        $config = Options::alloc()->plugin('TpHtmlCache');
        if (empty($config->allow_path)) return false;

        $allow_paths = explode(',', str_replace('，', ',', $config->allow_path));
        foreach ($allow_paths as $paths) {
            if (strstr($path, $paths)) {
                return true;
            }
        }

        return false;
    }
}
