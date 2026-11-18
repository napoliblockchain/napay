<?php

namespace app\widgets;

use yii\helpers\Html;
use yii\widgets\Menu;

/** Keep Yii menu visibility and route matching; render existing items for Tabler. */
class TablerSidebarMenu extends Menu
{
    public $options = ['class' => 'navbar-nav'];
    public $itemOptions = ['class' => 'nav-item'];
    public $activateParents = true;
    private int $groupIndex = 0;

    protected function renderItems($items)
    {
        $output = [];
        foreach ($items as $item) {
            $children = $item['items'] ?? [];
            if (count($children) === 1) {
                $output[] = parent::renderItems($children);
                continue;
            }
            if ($children === []) {
                $output[] = parent::renderItems([$item]);
                continue;
            }
            $id = $this->getId() . '-section-' . ++$this->groupIndex;
            $links = [];
            foreach ($children as $child) {
                $options = ['class'=>'dropdown-item' . ($child['active'] ? ' active' : ''),
                    'aria-current'=>$child['active'] ? 'page' : null];
                if (isset($child['target'])) {
                    $options['target'] = $child['target'];
                    if ($child['target'] === '_blank') $options['rel'] = 'noopener';
                }
                $links[] = Html::a($child['label'], $child['url'] ?? '#', $options);
            }
            $button = Html::button($this->icon($item) . Html::tag('span', $item['label'], ['class'=>'nav-link-title']), [
                'class'=>'nav-link dropdown-toggle', 'data-bs-toggle'=>'dropdown', 'data-bs-auto-close'=>'false',
                'aria-expanded'=>$item['active'] ? 'true' : 'false', 'aria-controls'=>$id,
            ]);
            $submenu = Html::tag('div', implode("\n", $links), ['id'=>$id, 'class'=>'dropdown-menu' . ($item['active'] ? ' show' : '')]);
            $options = array_merge($this->itemOptions, $item['options'] ?? []);
            Html::addCssClass($options, 'dropdown');
            if ($item['active']) Html::addCssClass($options, $this->activeCssClass);
            $output[] = Html::tag('li', $button . $submenu, $options);
        }
        return implode("\n", $output);
    }

    protected function renderItem($item)
    {
        // Menu::normalizeItems() has already encoded labels and removed hidden items.
        if (!empty($item['header'])) {
            return Html::tag('span', Html::tag('span', $item['label'], ['class' => 'nav-link-title']), ['class' => 'nav-link napay-menu-heading text-secondary small text-uppercase']);
        }
        $options = ['class' => 'nav-link', 'aria-current' => $item['active'] ? 'page' : null];
        if (isset($item['target'])) {
            $options['target'] = $item['target'];
            if ($item['target'] === '_blank') $options['rel'] = 'noopener';
        }
        return Html::a($this->icon($item) . Html::tag('span', $item['label'], ['class' => 'nav-link-title']),
            $item['url'] ?? '#', $options);
    }

    private function icon(array $item): string
    {
        return Html::tag('span', Html::tag('i', '', [
            'class'=>($item['iconStyle'] ?? 'fas') . ' fa-' . ($item['icon'] ?? 'circle') . ' fa-fw',
            'aria-hidden'=>'true',
        ]), ['class'=>'nav-link-icon']);
    }
}
