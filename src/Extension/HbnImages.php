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
    private const ORIENTATION_LANDSCAPE = 0;
    private const ORIENTATION_PORTRAIT = 1;
    private const ORIENTATION_SQUARE = 2;

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
            case 'com_content.featured':
                self::onContentPrepareArticle($article, $contextConfig);
                return;
            default:
                $this->log("No fitting configuration found. Leaving img tag as it is.");
                return;
        }
    }

    private function onContentPrepareArticle($article, object $contextConfig) : void {
        $article->text = preg_replace_callback('/<img [^>]*>/',
                                               function ($matches) use ($contextConfig) : string {
                                                   return self::createPicture($matches, $contextConfig);
                                               },
                                               $article->text);
    }

    private function createPicture(array $matches, object $contextConfig) : string {
        $img = $matches[0];

        $this->log("Create Picture: Processing {$img}");

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
                $this->log("Create Picture: No fitting class config found. Doing nothing.");
                return $img;
            }
        }

        $widths = get_object_vars($classConfig->mediawidths);
        $types = get_object_vars($this->params->get('types'));

        $avifSupported = $this->params->get('converter', 'joomla') !== 'imaginary';

        $lb = $this->getLightbox($srcUri, (int)$imgAttrs['width'], (int)$imgAttrs['height']);
        $pic = $lb;
        $pic .= '<picture>';

        foreach ($widths as $width) {
            foreach ($types as $type) {
                if ($type->type === 'avif' && !$avifSupported) {
                    continue;
                }
                $srcStr = $this->getResizedImage($srcUri, $width->width, 0, $type->type, $type->quality);
                if (empty($srcStr)) {
                    return $img;
                }
                $pic .= '<source' . $this->getType($type->type)
                      . ' srcset="' . $srcStr
                      . '" media="(' . $width->minmax . '-width: ' . $width->mediawidth . 'px)">';
            }
        }

        $pic .= $img;
        $pic .= '</picture>';
        if (!empty($lb)) {
            $pic .= '</a>';
        }

        return $pic;
    }

    private function getResizedImage(Uri $src, int $width, int $height = 0, string $type = 'webp', int $quality = 80) : string {
        $origFilePath = JPATH_ROOT . '/' . urldecode($src->getPath());
        $cacheFile = '';
        if ($width > 0) {
            $cacheFile = 'images/hbnimages/w' . (string)$width . '/' . File::stripExt($src->getPath()) . '.' . $type;
        } else if ($height > 0) {
            $cacheFile = 'images/hbnimages/h' . (string)$height . '/' . File::stripExt($src->getPath()) . '.' . $type;
        }


        if (!$this->createCacheDir(urldecode($cacheFile))) {
            return '';
        }

        $cacheFilePath = JPATH_ROOT . '/' . urldecode($cacheFile);

        if (file_exists($cacheFilePath)) {
            $origMTime = filemtime($origFilePath);
            $cacheMTime = filemtime($cacheFilePath);
            $this->log("Get Resized Image: Found cache file at {$cacheFilePath}");
            if ($cacheMTime >= $origMTime) {
                $this->log("Get Resized Image: Cache file is newer ({$cacheMTime} >= {$origMTime})");
                return $cacheFile;
            }
        }

        $converter = $this->params->get('converter', 'joomla');
        if ($converter === 'imaginary') {
            $srcUrl = Uri::root() . $src->getPath();
            if (!$this->getResizedImageImaginary($cacheFilePath, $srcUrl, $width, $height, $type, $quality)) {
                if (!$this->getResizedImageImagick($cacheFilePath, $origFilePath, $width, $height, $quality)) {
                    if (!$this->getResizedImageJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                        return '';
                    }
                }
            }
        } else if ($converter === 'imagick') {
            if (!$this->getResizedImageImagick($cacheFilePath, $origFilePath, $width, $height, $quality)) {
                if (!$this->getResizedImageJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                    return '';
                }
            }
        } else {
           $this->getResizedImageJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality);
        }

        return $cacheFile;
    }

    private function getResizedImageImaginary(string $cacheFilePath, string $srcUrl, int $width, int $height = 0, string $type = 'webp', int $quality = 80) : bool {
        $uriStr = $this->params->get('imaginary_host', 'http://localhost')
        . ':' . $this->params->get('imaginary_port', 9000)
        . $this->params->get('imaginary_path', '')
        . '/resize';

        $uri = new Uri($uriStr);
        $query = array(
            'type' => $type,
            'url' => $srcUrl,
            'quality' => $quality,
            'stripmeta' => ($this->params->get('stripmetadata', 0) === 0 ? 'false' : 'true')
        );
        if ($width > 0) {
            $query['width'] = $width;
        }
        if ($height > 0) {
            $query['height'] = $height;
        }
        $uri->setQuery($query);

        $this->log("Imaginary: Trying to generate resized image: {$uri->toString()}");

        try {
            $http = HttpFactory::getHttp();
        } catch (\Exception $ex) {
            $this->log("Imaginary: Failed to get Joomla HTTP instance: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        try {
            $response = $http->get($uri);
        } catch (\Exception $ex) {
            $this->log("Imaginary: Failed to get response: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        if ($response->code !== 200) {
            $errorMsg = json_decode($response->body)->message;
            $this->log("Imaginary: {$errorMsg}", Log::ERROR);
            return false;
        }

        try {
            File::write($cacheFilePath, $response->body);
        } catch (\Exception $ex) {
            $this->log("Imaginary: Failed to write cache file {$cacheFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        return true;
    }

    private function getResizedImageJoomla(string $cacheFilePath, string $origFilePath, int $width, int $height = 0, string $type = 'webp', int $quality = 80) : bool {
        $this->log("JImage: Trying to get resized image: {$origFilePath}");

        try {
            $img = new Image($origFilePath);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to load image {$origFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        $origWidth = $img->getWidth();
        $origHeight = $img->getHeight();

        $targetWidth = 0;
        $targetHeight = 0;

        if ($width > 0) {
            $targetWidth = $width;
            $ratio = $width / $origWidth;
            $targetHeight = (int)round($origHeight * $ratio);
        } else if ($height > 0) {
            $targetHeight = $height;
            $ratio = $height / $origHeight;
            $targetWidth = (int)round($origWidth * $ratio);
        }

        try {
            $img = $img->resize($targetWidth, $targetHeight);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to resize image {$origFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
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
                $this->log("JImage: Invalid file type: {$type}", Log::ERROR);
                return false;
        }

        $res = false;

        try {
            $res = $img->toFile($cacheFilePath, $imgType, ['quality' => $quality]);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to write image {$cacheFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        return $res;
    }

    private function getResizedImageImagick(string $cacheFilePath, string $origFilePath, int $width, int $height, int $quality = 80) : bool {
        $this->log("Imagick: Trying to get resized image: {$origFilePath}");

        if (!extension_loaded('imagick')) {
            $this->log('Imagick: extension not loaded', Log::WARNING);
            return false;
        }

        try {
            $img = new \Imagick();
        } catch (\Exception $ex) {
            $this->log("Imagick: Failed to get new object: {$ex->getMessage()}", Log::ERROR);
            return false;
        }


        if (!$img->readImage($origFilePath)) {
            $this->log("Imagick: Failed to read file {$origFilePath}", Log::ERROR);
            return false;
        }

        if (!$img->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1)) {
            $this->log("Imagick: Failed to resize image {$origFilePath}", Log::ERROR);
            $img->clear();
            $img->destroy();
            return false;
        }

        if ($this->params->get('stripmetadata', 0) !== 0) {
            $img->stripImage();
        }

        if (!$img->setImageCompressionQuality($quality)) {
            $this->log("Imagick: Failed to set compression quality to {$quality} for {$origFilePath}", Log::ERROR);
            $img->clear();
            $img->destroy();
            return false;
        }

        if (!$img->writeImage($cacheFilePath)) {
            $this->log("Imagick: Failed to write cache file {$cacheFilePath}", Log::ERROR);
            $img->clear();
            $img->destroy();
            return false;
        }

        $img->clear();
        $img->destroy();
        return true;
    }

    private function getLightbox(Uri $src, int $origWidth, int $origHeight) : string {
        $lightbox = $this->params->get('lightbox', 'none');
        switch ($lightbox) {
            case 'link':
                return $this->getLinkLightbox($src, $origWidth, $origHeight);
            case 'jcemediabox2':
                return $this->getJceMediaBox2($src, $origWidth, $origHeight);
            default:
                return '';
        }
    }

    private function getLinkLightbox(Uri $src, int $origWidth, int $origHeight) : string {
        $srcStr = '';
        if ($this->params->get('lightbox_resize', 0) === 1) {
            $orientation = $this->getOrientation($origWidth, $origHeight);

            if ($orientation == HbnImages::ORIENTATION_PORTRAIT) {
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
                $srcStr = $this->getResizedImage($src, 0, $targetHeight,
                                                 $this->params->get('lightbox_type', 'webp'),
                                                 $this->params->get('lightbox_quality', 80));
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
                $srcStr = $this->getResizedImage($src, $targetWidth, 0,
                                                 $this->params->get('lightbox_type', 'webp'),
                                                 $this->params->get('lightbox_quality', 80));
            }
        }

        if (empty($srcStr)) {
            $srcStr = $src->toString();
        }

        return '<a href="' . $srcStr . '">';
    }

    private function getJceMediaBox2(Uri $src, int $origWidth, int $origHeight) : string {
        $srcStr = '';
        if ($this->params->get('lightbox_resize', 0) === 1) {
            $orientation = $this->getOrientation($origWidth, $origHeight);

            if ($orientation == HbnImages::ORIENTATION_PORTRAIT) {
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
                $srcStr = $this->getResizedImage($src, 0, $targetHeight,
                                                 $this->params->get('lightbox_type', 'webp'),
                                                 $this->params->get('lightbox_quality', 80));
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
                $srcStr = $this->getResizedImage($src, $targetWidth, 0,
                                                 $this->params->get('lightbox_type', 'webp'),
                                                 $this->params->get('lightbox_quality', 80));
            }
        }

        if (empty($srcStr)) {
            $srcStr = $src->toString();
        }

        $gallery = '';
        if ($this->params->get('lightbox_gallery', 0) !== 0) {
            $gallery = ' data-mediabox-group="hbnimages-found-gallery"';
        }

        return '<a class="jcepopup" href="' . $srcStr . '"' . $gallery . '>';
    }

    private function createCacheDir(string $cacheFilePath) : bool {
        $dirName = dirname($cacheFilePath);
        if (file_exists(JPATH_ROOT . '/' . $dirName)) {
            return true;
        }
        $parts = array_filter(explode('/', $dirName));
        if (empty($parts)) {
            return true;
        }

        $currentPath = JPATH_ROOT;
        foreach ($parts as $part) {
            $currentPath .= '/' . $part;
            if (!file_exists($currentPath)) {
                try {
                    Folder::create($currentPath);
                } catch (\Joomla\Filesystem\Exception\FilesystemException $ex) {
                    $this->log("Create Cache Dir: Failed to create directory {$currentPath}: {$ex->getMessage()}", Log::ERROR);
                    return false;
                }
            }
            $indexFile = $currentPath . '/index.html';
            if (!file_exists($indexFile)) {
                if (!File::write($indexFile, '<!DOCTYPE html><title></title>')) {
                    $this->log("Cratea Cache Dir: Failed to write index file {$indexFile}", Log::ERROR);
                    return false;
                }
            }
        }

        return true;
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
                $this->log("Check Is Our: Not our image. Doing nothing.", "checkIsOurImage");
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

    private function getOrientation(int $width, int $height) : int {
        if ($width > $height) {
            return HbnImages::ORIENTATION_LANDSCAPE;
        } else if ($height > $width) {
            return HbnImages::ORIENTATION_PORTRAIT;
        } else {
            return HbnImages::ORIENTATION_SQUARE;
        }
    }

    private function log(string $message, int $prio = Log::DEBUG) : void {
        Log::add($message, $prio, 'plugin.content.hbnimages');
    }
}
