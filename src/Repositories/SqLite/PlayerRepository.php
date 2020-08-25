<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Repositories\SqLite;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use Nawarian\KFStats\Entities\Player\Player;

final class PlayerRepository implements \Nawarian\KFStats\Entities\Player\PlayerRepository
{
    private PDO $client;

    public function __construct(PDO $client)
    {
        $this->client = $client;
    }

    public function store(Player $player): void
    {
        $props = get_object_vars($player);
        $propKeys = array_keys($props);
        $propPlaceholders = array_map(function (string $key) {
            return ':' . $key;
        }, $propKeys);

        $insert = sprintf(
            'INSERT OR REPLACE INTO player (%s) VALUES (%s)',
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

    public function fetchById(int $playerId): Player
    {
        $prepared = $this->client->prepare('SELECT * FROM player WHERE player.id = :id');

        $prepared->execute([
            ':id' => $playerId,
        ]);

        $player = new Player();
        $rawPLayer = $prepared->fetchObject();
        foreach (get_object_vars($rawPLayer) as $property => $value) {
            switch ($property) {
                case 'name':
                case 'url':
                    $value = (string) $value;
                    break;
                case 'currentHP':
                    $value = (float) $value;
                    break;
                case 'createdAt':
                    $value = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
                    break;
                default:
                    $value = (int) $value;
                    break;
            }

            $player->{$property} = $value;
        }

        return $player;
    }

    public function fetchPlayersWeakerThan(Player $player, int $limit, int $offset): iterable
    {
        $prepared = $this->client->prepare(<<<QUERY
            SELECT
                player.id
            FROM player
            WHERE
                player.id <> :id
                AND player.strength <= :str
                AND player.stamina <= :sta
                AND player.dexterity <= :dex
                AND player.fightingAbility <= :fa
                AND player.parry <= :parry

                AND player.armour <= :arm
                AND player.oneHandedAttack <= :one
                AND player.twoHandedAttack <= :two
            ORDER BY
                player.goldLost DESC
                , player.createdAt DESC
            LIMIT :limit
            OFFSET :offset
        QUERY);

        $prepared->execute([
            'id' => $player->id,

            'str' => $player->strength,
            'sta' => $player->stamina,
            'dex' => $player->dexterity,
            'fa' => $player->fightingAbility,
            'parry' => $player->parry,

            'arm' => $player->armour,
            'one' => $player->oneHandedAttack,
            'two' => $player->twoHandedAttack,

            'limit' => $limit,
            'offset' => $offset,
        ]);

        $ids = $prepared->fetchAll(PDO::FETCH_COLUMN);

        $result = [];
        foreach ($ids as $weakerPlayerId) {
            $result[] = $this->fetchById((int) $weakerPlayerId);
        }

        return $result;
    }
}
