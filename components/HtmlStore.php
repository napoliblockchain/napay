<?php
namespace app\components;

use Yii;


class HtmlStore extends \yii\helpers\Html
{
    public static function paymentMethodCriteria($json)
    {
        // echo "<pre>" . print_r($json, true) . "</pre>";exit;
        $text = '';
        if (null !== $json){
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            // echo "<pre>" . print_r($data, true) . "</pre>";exit;

            unset($data[2]);

            $type = [
                0 => Yii::t('app', 'Inferiore a'),
                1 => Yii::t('app', 'Superiore a'),
            ];
    
    
            $text .= '
            <div class="form-group">
                <div class="form-label mb-1">'.Yii::t('app', 'Abilita i metodi di pagamento solo quando l\'importo è …').'</div>
                    <table class="table table-secondary table-sm mt-0 mx-0">
                        <tbody>';
    
            foreach ($data as $row){
                $text .= '<tr>
                    <td class="border-0 ps-0 align-middle">' . $row['paymentMethod'] . '</td>
                    <td class="border-0 ps-0 align-middle">' . $type[$row['above']] . '</td>
                    <td class="border-0 ps-0 align-middle">' . $row['amount'] . chr(32) . $row['currencyCode'] . '</td>
                </tr>';
            }
    
            $text .= '  </tbody>
                        </table>
                    </div>';
        }
      
        return $text;
    }

    /**
     * [enabled] => 1
     * [showQR] => 1
     * [showPayments] => 1 
     */
    public static function receipt($json)
    {
        $data = json_decode($json);
        // echo "<pre>" . print_r($data, true) . "</pre>";exit;

        $checked = ['<i class="far fa-square not-checked-icon text-danger mr-2"></i>', '<i class="fas fa-check-square checked-icon text-success mr-2"></i>'];

        $text = '<div class="form-check">' . $checked[$data->enabled] . '
                <label class="form-check-label" for="ReceiptOptions_Enabled">
                    '.Yii::t('app', 'Abilita pagina di ricevuta per transazioni saldate'). '
                </label>
            </div>
            <div class="form-check">' . $checked[$data->showPayments] . ' 
                <label class="form-check-label" for="ReceiptOptions_ShowPayments">
                    ' . Yii::t('app', 'Mostra l\'elenco dei pagamenti nella pagina di ricevuta') . '
                </label>
            </div>
            <div class="form-check">'. $checked[$data->showQR] . '
                <label class="form-check-label" for="ReceiptOptions_ShowQR">
                    ' . Yii::t('app', 'Mostra il codice QR della ricevuta nella pagina di ricevuta') . '
                </label>
            </div>
            ';

        return $text;

    }
    
}
