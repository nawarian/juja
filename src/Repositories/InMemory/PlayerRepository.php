<?php

declare(strict_types=1);

namespace Nawarian\Juja\Repositories\InMemory;

use Nawarian\Juja\Entities\Player\Player;
use RuntimeException;

final class PlayerRepository implements \Nawarian\Juja\Entities\Player\PlayerRepository
{
    private array $players = [];

    public function store(Player $player): void
    {
        $this->players[$player->id] = $player;
    }

    public function fetchById(int $playerId): Player
    {
        $player = $this->players[$playerId] ?? null;

        if ($player === null) {
            throw new RuntimeException("Player '{$playerId}' not found.");
        }

        return $player;
    }

    public function fetchPlayersWeakerThan(Player $player, int $limit, int $offset): iterable
    {
        return [];
    }

    public function fetchPlayersWeakerAndWithHigherLevelThan(Player $player, int $limit, int $offset): iterable
    {
        return [];
    }
}
