<?php

namespace HBN\Plugin\Content\HbnImages\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Image\Image;
use Joomla\CMS\Log\Log;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

class HbnImages extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents() : array {
        return [
            'onContentPrepare' => 'onContentPrepare'
        ];
    }

    public function onContentPrepare(ContentPrepareEvent $event) : void {

        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $cConfigs = $this->params->get('context', null);
        if (empty($cConfigs)) {
            return;
        }

        [$context, $article, $params, $page] = array_values($event->getArguments());

        $defaultContext = null;
        $contextConfig = null;

        foreach ($cConfigs as $cc) {
            if ($cc->name === $context) {
                $contextConfig = $cc;
            } else if ($cc->name === 'default') {
                $defaultContext = $cc;
            }
        }

        if (empty($contextConfig)) {
            $contextConfig = $defaultContext;
        }

        if (empty($contextConfig)) {
            return;
        }

        switch ($context) {
            case 'com_content.article':
                self::onContentPrepareArticle($article, $contextConfig);
                return;
            default:
                return;
        }
    }

    private function onContentPrepareArticle($article, object $contextConfig) : void {
        $article->text = preg_replace_callback('/<img [^>]*>/',
                                               function ($matches) use ($contextConfig) : string {
                                                   return self::createArticlePicture($matches, $contextConfig);
                                               },
                                               $article->text);
    }

    private function createArticlePicture(array $matches, object $contextConfig) : string {
        $img = $matches[0];

        $this->log("Found img tag {$img}");

        $imgAttrs = $this->getImgTagAttrs($img);
        if (empty($imgAttrs)) {
            return $img;
        }

        $srcUri = $this->checkIsOurImage($imgAttrs['src']);
        if ($srcUri === false) {
            return $img;
        }

        $imgClasses = array_key_exists('class', $imgAttrs) ? $imgAttrs['class'] : null;

        $defaultClassConfig = null;
        $classConfig = null;
        $classConfigs = $contextConfig->classes;
        foreach($classConfigs as $cc) {
            if ($cc->name === 'default') {
                $defaultClassConfig = $cc;
            } else if (!empty($imgClasses) && array_search($cc->name, $imgClasses) !== false) {
                $classConfig = $cc;
            }
        }

        if (empty($classConfig)) {
            $classConfig = $defaultClassConfig;
            if (empty($classConfig)) {
                return $img;
            }
        }

        $widths = get_object_vars($classConfig->mediawidths);
        $types = get_object_vars($this->params->get('types'));

        $avifSupported = $this->params->get('converter', 'joomla') !== 'imaginary';

        $pic = '<picture>';

        foreach ($widths as $width) {
            foreach ($types as $type) {
                if ($type->type === 'avif' && !$avifSupported) {
                    continue;
                }
                $pic .= '<source' . $this->getType($type->type)
                      . ' srcset="' . $this->getResizedImage($srcUri, $width->width, $type->type, $type->quality)
                      . '" media="(' . $width->minmax . '-width: ' . $width->mediawidth . 'px)">';
            }
        }

        $pic .= $img;
        $pic .= '</picture>';

        return $pic;
    }

    private function getResizedImage(Uri $src, int $width, string $type = 'webp', int $quality = 80) : string {
        $origFilePath = JPATH_ROOT . '/' . urldecode($src->getPath());
        $cacheFile = 'cache/hbnimages/' . (string)$width . '/' . File::stripExt($src->getPath()) . '.' . $type;

        $this->createCacheDir(urldecode($cacheFile));

        $cacheFilePath = JPATH_ROOT . '/' . urldecode($cacheFile);

        $this->log("Trying to get cache file for {$origFilePath}");

        if (file_exists($cacheFilePath)) {
            $this->log("Cache file {$cacheFilePath} already exists");
            if (filemtime($cacheFilePath) >= filemtime($origFilePath)) {
                $this->log("Cache file is newer");
                return $cacheFile;
            }
        }

        $converter = $this->params->get('converter', 'joomla');
        if ($converter === 'imaginary') {
            $srcUrl = Uri::root() . $src->getPath();
            $this->getResizedImageImaginary($cacheFilePath, $srcUrl, $width, $type, $quality);
        } else if ($converter === 'imagick') {
            $this->getResizedImageImagick($cacheFilePath, $origFilePath, $width, $quality);
        } else {
           $this->getResizedImageJoomla($cacheFilePath, $origFilePath, $width, $type, $quality);
        }

        return $cacheFile;
    }

    private function getResizedImageImaginary(string $cacheFilePath, string $srcUrl, int $width, string $type = 'webp', int $quality = 80) : bool {
        $uriStr = $this->params->get('imaginary_host', 'http://localhost')
        . ':' . $this->params->get('imaginary_port', 9000)
        . $this->params->get('imaginary_path', '')
        . '/resize';

        $uri = new Uri($uriStr);
        $uri->setQuery([
            'width' => $width,
            'type' => $type,
            'url' => $srcUrl,
            'quality' => $quality,
            'stripmeta' => ($this->params->get('stripmetadata', '0') === '0' ? 'false' : 'true')
        ]);

        $this->log("Trying to generate resized image with Imaginary: {$uri->toString()}");

        $http = HttpFactory::getHttp();
        $response = $http->get($uri);

        File::write($cacheFilePath, $response->body);

        return true;
    }

    private function getResizedImageJoomla(string $cacheFilePath, string $origFilePath, int $width, string $type = 'webp', int $quality = 80) : bool {
        $img = new Image($origFilePath);

        if ($img->getWidth() != $width) {
            $origWidth = $img->getWidth();
            $origHeight = $img->getHeight();
            $ratio = $width / $origWidth;
            $height = (int)round($origHeight * $ratio);
            $img = $img->resize($width, $height);
        }

        $imgType = IMAGETYPE_WEBP;

        switch ($type) {
            case 'webp':
                $imgType = IMAGETYPE_WEBP;
                break;
            case 'avif':
                $imgType = IMAGETYPE_AVIF;
                break;
            case 'jpeg':
                $imgType = IMAGETYPE_JPEG;
                break;
            default:
                return false;
        }

        return $img->toFile($cacheFilePath, $imgType, ['quality' => $quality]);
    }

    private function getResizedImageImagick(string $cacheFilePath, string $origFilePath, int $width, int $quality = 80) : bool {
        $img = new \Imagick();

        if (!$img->readImage($origFilePath)) {
            return false;
        }

        if (!$img->resizeImage($width, 0, \Imagick::FILTER_LANCZOS, 1)) {
            $img->clear();
            $img->destroy();
            return false;
        }

        if (!$img->setImageCompressionQuality($quality)) {
            $img->clear();
            $img->destroy();
            return false;
        }

        if (!$img->writeImage($cacheFilePath)) {
            $img->clear();
            $img->destroy();
            return false;
        }

        $img->clear();
        $img->destroy();
        return true;
    }

    private function createCacheDir(string $cacheFilePath) : void {
        $dirName = dirname($cacheFilePath);
        if (file_exists(JPATH_ROOT . '/' . $dirName)) {
            return;
        }
        $parts = array_filter(explode('/', $dirName));
        if (empty($parts)) {
            return;
        }

        $currentPath = JPATH_ROOT;
        foreach ($parts as $part) {
            $currentPath .= '/' . $part;
            if (!file_exists($currentPath)) {
                Folder::create($currentPath);
            }
            $indexFile = $currentPath . '/index.html';
            if (!file_exists($indexFile)) {
                File::write($indexFile, '<!DOCTYPE html><title></title>');
            }
        }
    }

    private function getImgTagAttrs(string $imgTag) : array {
        $attrs = array();

        $matches = array();
        preg_match_all('/([\w-]+)=[\'"]([^"\']+)[\'"]/', $imgTag, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            return $data;
        }

        $data = array();
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            if (str_starts_with($key, 'data-')) {
                $data[substr($key, 5)] = $value;
            } else {
                $attrs[$key] = $value;
            }
        }
        if (!empty($data)) {
            $attrs['data'] = $data;
        }

        if (array_key_exists('class', $attrs)) {
            $attrs['class'] = preg_split('/[\s]+/', $attrs['class']);
        }

        return $attrs;
    }

    private function checkIsOurImage(string $src) : Uri|bool {
        $srcUri = new Uri($src);
        if (!empty($srcUri->getHost())) {
            $myUri = new Uri(Uri::root());
            if ($srcUri->getHost() !== $myUri->getHost()) {
                return false;
            }
        }

        return $srcUri;
    }

    private function getType(string $type) : string {
        switch($type) {
            case 'webp':
                return ' type="image/webp"';
            case 'avif':
                return ' type="image/avif"';
            case 'jpeg':
                return ' type="image/jpeg"';
        }
    }

    private function log(string $message, int $prio = Log::DEBUG) : void {
        Log::add($message, $prio, 'hbn.plugin.content.hbnimages');
    }
}
