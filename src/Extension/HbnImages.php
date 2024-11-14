<?php

namespace HBN\Plugin\Content\HbnImages\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Document\Document;

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

        [$context, $article, $params, $page] = array_values($event->getArguments());

        switch ($context) {
            case 'com_content.article':
                self::onContentPrepareArticle($article);
                return;
            default:
                return;
        }
    }

    private function onContentPrepareArticle($article) : void {
        $article->text = preg_replace_callback('/<img [^>]*>/',
                                               [$this, 'createPicture'],
                                               $article->text);
    }

    private function createPicture(array $matches) : string {
        $img = $matches[0];
        $ri = self::getResizedImage($img, 0);
        return '<picture>' . $img . '</picture>';
    }

    private function getResizedImage(string $img, int $width) : string {
        $matches = array();
        if (preg_match('/src=["\']([^"\']+)/', $img, $matches) !== 1) {
            return '';
        }

        $src = $matches[1];

        // echo '<pre>';
        // print_r($matches);
        // echo '</pre>';
        return $img;
    }
}
