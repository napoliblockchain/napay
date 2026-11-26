<?php
namespace app\components\pos\btc;

/** Esito della creazione della fattura sconosciuto (timeout o errore dopo l'invio): solo riconciliazione, mai un secondo POST. */
final class PosInvoiceUnknown extends \RuntimeException
{
}
