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
use HBN\Images\HbnImages as HbnLibImg;

class HbnImages extends CMSPlugin implements SubscriberInterface
{
    private const ORIENTATION_LANDSCAPE = 0;
    private const ORIENTATION_PORTRAIT = 1;
    private const ORIENTATION_SQUARE = 2;

    private $hbnLibImg = null;

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
                $this->onContentPrepareArticle($article, $contextConfig);
                return;
            default:
                $this->log("No fitting configuration found. Leaving img tag as it is.");
                return;
        }
    }

    private function onContentPrepareArticle($article, object $contextConfig) : void {
//         (?(?=<(?P<tag>figure|picture))(<(?&tag) [^>]+>.*<\/(?&tag)>)|(<img [^>]+>))
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

        $excludedexts = array_filter(explode(',', $this->params->get('excludedexts', 'svg')));

        if (array_search($imgAttrs['ext'], $excludedexts) !== false) {
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

        if ($this->hbnLibImg === null) {
            $libOpts = [
                'converter' => $this->params->get('converter', 'joomla'),
                'stripmetadata' => $this->params->get('stripmetadata', 0)
            ];
            if ($libOpts['converter'] === 'imaginary') {
                $libOpts['imaginary_host'] = $this->params->get('imaginary_host', 'http://localhost');
                $libOpts['imaginary_port'] = $this->params->get('imaginary_port', 9000);
                $libOpts['imaginary_path'] = $this->params->get('imaginary_path', '');
                $libOpts['imaginary_token'] = $this->params->get('imaginary_token', '');
            }
            $this->hbnLibImg = new HbnLibImg($libOpts);
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

                $currentWidth = $width->width;
                $currentHeight = 0;

                $srcStr = $this->hbnLibImg->resizeImage($srcUri, $currentWidth, $currentHeight, $type->type, $type->quality);
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
                $targetWidth = 0;
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
                $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
                                                        $this->params->get('lightbox_type', 'webp'),
                                                        $this->params->get('lightbox_quality', 80));
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
                $targetHeight = 0;
                $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
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
                $targetWidth = 0;
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
                $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
                                                        $this->params->get('lightbox_type', 'webp'),
                                                        $this->params->get('lightbox_quality', 80));
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
                $targetHeight = 0;
                $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
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

    private function getImgTagAttrs(string $imgTag) : array {
        $attrs = array();

        $matches = array();
        preg_match_all('/([\w-]+)=[\'"]([^"\']+)[\'"]/', $imgTag, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            $this->log("Invalid img tag", Log::ERROR);
            return array();
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

        if (!array_key_exists('src', $attrs)) {
            $this->log("Invalid img tag", Log::ERROR);
            return array();
        }

        $attrs['ext'] = File::getExt($attrs['src']);

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
