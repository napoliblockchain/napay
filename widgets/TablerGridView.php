<?php

namespace app\widgets;

use Yii;
use yii\grid\ActionColumn;
use yii\grid\DataColumn;
use yii\helpers\Html;

/** Shared presentation for backoffice lists; queries and permissions stay with callers. */
class TablerGridView extends \yii\grid\GridView
{
    public $tableOptions = ['class' => 'table table-vcenter card-table'];
    public $layout = "<div class=\"table-responsive\">{items}</div>\n<div class=\"card-footer d-flex flex-wrap align-items-center gap-2\">{summary}<div class=\"ms-auto\">{pager}</div></div>";
    public $summaryOptions = ['class' => 'text-secondary small'];
    public $emptyTextOptions = ['class' => 'text-secondary py-4'];

    protected function initColumns()
    {
        parent::initColumns();
        $data = $actions = [];
        foreach ($this->columns as $column) {
            if ($column instanceof DataColumn && $column->attribute === 'id') {
                continue;
            }
            if ($column instanceof ActionColumn) {
                $column->header = Html::encode(Yii::t('app', 'Azioni'));
                Html::addCssClass($column->headerOptions, 'text-end w-1');
                // Preserve closure-based cell attributes and all caller action callbacks.
                $options = $column->contentOptions;
                $column->contentOptions = static function ($model, $key, $index, $cell) use ($options) {
                    $attributes = is_callable($options) ? $options($model, $key, $index, $cell) : $options;
                    Html::addCssClass($attributes, 'text-end text-nowrap');
                    return $attributes;
                };
                $actions[] = $column;
            } else {
                $data[] = $column;
            }
        }
        $this->columns = array_merge($data, $actions);
    }
}
