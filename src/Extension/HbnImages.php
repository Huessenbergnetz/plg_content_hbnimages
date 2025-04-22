<?php

namespace HBN\Plugin\Content\HbnImages\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Image\Image;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Component\ComponentHelper;
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
            'onContentPrepare' => 'onContentPrepare',
            'onContentAfterSave' => 'onContentAfterSave',
            'onContentAfterDelete' => 'onContentAfterDelete'
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

        $this->initHbnImgLib();

        if (!array_key_exists('width', $imgAttrs) || !array_key_exists('height', $imgAttrs)) {
            array_merge($imgAttrs, $this->hbnLibImg->getImageDimensions($srcUri->getPath()));
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

            $targetWidth  = 0;
            $targetHeight = 0;

            if ($orientation == HbnImages::ORIENTATION_PORTRAIT) {
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
            }

            $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
                                                    $this->params->get('lightbox_type', 'webp'),
                                                    $this->params->get('lightbox_quality', 80));
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

            $targetWidth  = 0;
            $targetHeight = 0;

            if ($orientation == HbnImages::ORIENTATION_PORTRAIT) {
                $targetHeight = $this->params->get('lightbox_height', 0);
                $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
            } else {
                $targetWidth = $this->params->get('lightbox_width', 0);
                $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
            }

            $srcStr = $this->hbnLibImg->resizeImage($src, $targetWidth, $targetHeight,
                                                    $this->params->get('lightbox_type', 'webp'),
                                                    $this->params->get('lightbox_quality', 80));
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

    /**
     * @brief Returns the attributes of the @a imgTag as a named array.
     *
     * Attribute keys will be the array keys, attribute values will be the array values.
     * data-attributes will be in the data key of the array as a named array. If there is
     * e.g. an img tag like
     * &lt;img src="file.jpg" width="123" height="456" class="thumb picture" data-foo="bar"&gt;,
     * it will return an array like
     * ["src => "file.jpg", "ext" => "jpg", "width" => 123, "height" => 456, "class" => ["thumb", "picture"], "data" => ["foo" => "bar"]]
     */
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

    /**
     * @brief Returns @a src as Joomla\CMS\Uri\Uri if the host of the @a src is ours,
     * otherwise returns @c false.
     */
    private function checkIsOurImage(string $src) : Uri|bool {
        $srcUri = new Uri($src);
        if (!empty($srcUri->getHost())) {
            $myUri = new Uri(Uri::root());
            if ($srcUri->getHost() !== $myUri->getHost()) {
                $this->log("Check Is Our: Not our image. Doing nothing.");
                return false;
            }
        }

        return $srcUri;
    }

    /**
     * @brief Returns a type tag string for an image based on the image type.
     *
     * @param $type string Has to be webp, avif or jpeg.
     * @return A string like ' type="image/webp"'.
     */
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

    /**
     * @brief Returns the image orientation based on the image’s width and height.
     */
    private function getOrientation(int $width, int $height) : int {
        if ($width > $height) {
            return HbnImages::ORIENTATION_LANDSCAPE;
        } else if ($height > $width) {
            return HbnImages::ORIENTATION_PORTRAIT;
        } else {
            return HbnImages::ORIENTATION_SQUARE;
        }
    }

    public function onContentAfterSave(AfterSaveEvent $event) : void {
        if ((int)$this->params->get('createthumbsonupload', 1) === 0) {
            return;
        }

        $context = $event->getContext();

        if ($context != 'com_media.file') {
            return;
        }

        $item = $event->getItem();

        if (!\in_array(strtolower($item->extension), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
            return;
        }

        $adapter = $item->adapter;
        $fileName = $item->name;
        $filePath = $item->path;

        $types = $this->params->get('types');

        $widths = array();
        $contexts = $this->params->get('context');
        foreach ($contexts as $context) {
            $classes = $context->classes;
            foreach($classes as $class) {
                $mediawidths = $class->mediawidths;
                foreach ($mediawidths as $mediawidth) {
                    $w = (int)$mediawidth->width;
                    if ($w > 0 && !\in_array($w, $widths, true)) {
                        $widths[] = $w;
                    }
                }
            }
        }

        $additionalWidths = array_filter(explode(',', $this->params->get('additionalwidths', '')));
        foreach ($additionalWidths as $addWidth) {
            $aw = (int)$addWidth;
            if ($aw > 0 && !\in_array($aw, $widths, true)) {
                $widths[] = $aw;
            }
        }

        $sourceFilePath = ComponentHelper::getParams('com_media')->get('image_path') . $filePath . '/' . $fileName;
        $sourceFileUri = new Uri($sourceFilePath);

        $this->initHbnImgLib();

        foreach ($widths as $width) {
            foreach ($types as $type) {
                $currentWidth = $width;
                $currentHeight = 0;
                $this->hbnLibImg->resizeImage($sourceFileUri, $currentWidth, $currentHeight, $type->type, $type->quality);
            }
        }

        // create thumbnail for lightbox size
        $dimensions = $this->hbnLibImg->getImageDimensions($sourceFilePath);
        $origWidth = $dimensions["width"];
        $origHeight = $dimensions["height"];
        $orientation = $this->getOrientation($origWidth, $origHeight);

        $targetWidth  = 0;
        $targetHeight = 0;

        if ($orientation == HbnImages::ORIENTATION_PORTRAIT) {
            $targetHeight = $this->params->get('lightbox_height', 0);
            $targetHeight = $targetHeight > 0 ? $targetHeight : $origHeight;
        } else {
            $targetWidth = $this->params->get('lightbox_width', 0);
            $targetWidth = $targetWidth > 0 ? $targetWidth : $origWidth;
        }

        $this->hbnLibImg->resizeImage($sourceFileUri, $targetWidth, $targetHeight,
                                      $this->params->get('lightbox_type', 'webp'),
                                      $this->params->get('lightbox_quality', 80));
    }

    public function onContentAfterDelete(AfterDeleteEvent $event) : void
    {
        $context = $event->getContext();

        if ($context != 'com_media.file') {
            return;
        }

        $item = $event->getItem();
        $adapter = $item->adapter;
        $filePath = $item->path;

        $sourceFilePath = ComponentHelper::getParams('com_media')->get('image_path') . $filePath;

        $this->initHbnImgLib();

        $this->hbnLibImg->deleteThumbnails($sourceFilePath);
    }

    private function initHbnImgLib() : void {
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
    }

    /**
     * @brief Writes a log message to the Joomla! log.
     */
    private function log(string $message, int $prio = Log::DEBUG) : void {
        Log::add($message, $prio, 'plugin.content.hbnimages');
    }
}
