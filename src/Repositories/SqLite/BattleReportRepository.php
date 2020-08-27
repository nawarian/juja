<?php

declare(strict_types=1);

namespace Nawarian\Juja\Repositories\SqLite;

use DateTimeImmutable;
use DateTimeInterface;
use Nawarian\Juja\Entities\Battle\BattleReport;
use PDO;

final class BattleReportRepository implements \Nawarian\Juja\Entities\Battle\BattleReportRepository
{
    private PDO $client;

    public function __construct(PDO $client)
    {
        $this->client = $client;
    }

    public function store(BattleReport $battleReport): void
    {
        $props = get_object_vars($battleReport);
        $propKeys = array_keys($props);
        $propPlaceholders = array_map(function (string $key) {
            return ':' . $key;
        }, $propKeys);

        $insert = sprintf(
            'INSERT OR REPLACE INTO battle_report (%s) VALUES (%s)',
            implode(',', $propKeys),
            implode(',', $propPlaceholders)
        );

        $prepared = $this->client->prepare($insert);
        foreach ($props as $prop => $value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $prepared->bindValue(':' . $prop, $value);
        }

        $prepared->execute();
    }

    public function findByAttackerIdAndVictimId(int $attackerId, int $victimId): iterable
    {
        $prepared = $this->client->prepare(<<<QUERY
            SELECT
              *
            FROM battle_report
            WHERE
                attackerId = :attacker
                AND victimId = :victim
        QUERY);

        $prepared->execute([
            ':attacker' => $attackerId,
            ':victim' => $victimId,
        ]);

        $result = [];
        while ($rawBattleReport = $prepared->fetchObject()) {
            $report = new BattleReport();
            foreach (get_object_vars($rawBattleReport) as $property => $value) {
                if ('date' === $property) {
                    $value = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
                } else {
                    $value = (int) $value;
                }

                $report->{$property} = $value;
            }

            $result[] = $report;
        }

        return $result;
    }
}
