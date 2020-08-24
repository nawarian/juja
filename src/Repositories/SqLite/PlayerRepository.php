<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Repositories\SqLite;

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
            'INSERT INTO player (%s) VALUES (%s)',
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
        $prepared->bindValue('id', $playerId, PDO::PARAM_INT);

        return $prepared->fetchObject(Player::class);
    }
}
