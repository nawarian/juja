<?php

declare(strict_types=1);

namespace Nawarian\KFStats\Repositories\InMemory;

use Nawarian\KFStats\Entities\Player\Player;
use RuntimeException;

final class PlayerRepository implements \Nawarian\KFStats\Entities\Player\PlayerRepository
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
}
