<?php
namespace app\components\geo;

use Yii;

/** Comuni ISTAT (tabella istat_comuni): suggerimenti e risoluzione di città e provincia degli indirizzi. */
final class ItalianMunicipalities
{
    public const LIMIT = 20;

    /** @return list<string> "Comune (SIGLA)" per i comuni che iniziano con il testo, confronto senza maiuscole e accenti */
    public static function suggest(string $text): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 2 || mb_strlen($text) > 60) return [];
        $rows = Yii::$app->db->createCommand('SELECT denominazione, sigla FROM {{%istat_comuni}} WHERE denominazione LIKE :p ORDER BY denominazione, sigla LIMIT ' . self::LIMIT,
            [':p' => addcslashes($text, '%_\\') . '%'])->queryAll();
        return array_map(static fn(array $row) => $row['denominazione'] . ' (' . $row['sigla'] . ')', $rows);
    }

    /**
     * "Comune" oppure "Comune (SIGLA)": nome ufficiale e sigla, null se assente.
     * @return array{name:string, province:string}|array{ambiguous:true}|null
     */
    public static function resolve(string $input): ?array
    {
        $input = trim($input);
        $sigla = null;
        if (preg_match('/^(.+?)\s*\(([A-Za-z]{2})\)$/Du', $input, $match)) { $input = trim($match[1]); $sigla = strtoupper($match[2]); }
        if ($input === '' || mb_strlen($input) > 200) return null;
        $params = [':n' => $input];
        $sql = 'SELECT denominazione, sigla FROM {{%istat_comuni}} WHERE denominazione = :n';
        if ($sigla !== null) { $sql .= ' AND sigla = :s'; $params[':s'] = $sigla; }
        $rows = Yii::$app->db->createCommand($sql . ' LIMIT 2', $params)->queryAll();
        if (count($rows) > 1) return ['ambiguous' => true];
        return $rows === [] ? null : ['name' => $rows[0]['denominazione'], 'province' => $rows[0]['sigla']];
    }
}
