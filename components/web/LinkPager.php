<?php

namespace app\components\web;

use Yii;

/**
 * Paginazione unica dell'applicazione (come in Aura): stesso aspetto Bootstrap 5/Tabler in tutti gli elenchi,
 * anche per le voci disattivate. Registrata in config/web.php al posto di yii\widgets\LinkPager:
 * le griglie la usano senza configurazione nelle viste.
 */
class LinkPager extends \yii\bootstrap5\LinkPager
{
    public $listOptions = ['class' => ['pagination', 'pagination-sm', 'justify-content-center', 'mb-0']];
    public $maxButtonCount = 5;

    public function init(): void
    {
        // etichette tradotte: in fase di configurazione la lingua non è ancora impostata
        $this->firstPageLabel = Yii::t('app', 'Prima');
        $this->lastPageLabel = Yii::t('app', 'Ultima');
        parent::init();
    }
}
