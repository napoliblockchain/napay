<?php

namespace app\widgets;

use yii\helpers\Html;
use yii\widgets\Menu;

/** Keep Yii menu visibility and route matching; render existing items for Tabler. */
class TablerSidebarMenu extends Menu
{
    public $options = ['class' => 'navbar-nav'];
    public $itemOptions = ['class' => 'nav-item'];

    protected function renderItem($item)
    {
        // Menu::normalizeItems() has already encoded labels and removed hidden items.
        if (!empty($item['header'])) {
            return Html::tag('span', Html::tag('span', $item['label'], ['class' => 'nav-link-title']), ['class' => 'nav-link napay-menu-heading text-secondary small text-uppercase']);
        }
        $icon = Html::tag('span', Html::tag('i', '', [
            'class' => ($item['iconStyle'] ?? 'fas') . ' fa-' . ($item['icon'] ?? 'circle') . ' fa-fw',
            'aria-hidden' => 'true',
        ]), ['class' => 'nav-link-icon']);
        $options = ['class' => 'nav-link', 'aria-current' => $item['active'] ? 'page' : null];
        if (isset($item['target'])) {
            $options['target'] = $item['target'];
            if ($item['target'] === '_blank') $options['rel'] = 'noopener';
        }
        return Html::a($icon . Html::tag('span', $item['label'], ['class' => 'nav-link-title']),
            $item['url'] ?? '#', $options);
    }
}
