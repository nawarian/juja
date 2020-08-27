<?php

declare(strict_types=1);

namespace Nawarian\Juja\Entities\Player;

use DateTimeInterface;

final class Player
{
    // Properties
    public int $id;

    public string $name;

    public int $level = 1;

    public int $alignment = 0;

    public float $currentHP = 1.00;

    public int $maxHP = 100;

    public int $experience = 1;

    public DateTimeInterface $createdAt;

    // Attributes
    public int $strength = 5;

    public int $stamina = 5;

    public int $dexterity = 5;

    public int $fightingAbility = 5;

    public int $parry = 5;

    // Skills
    public int $armour = 0;

    public int $oneHandedAttack = 0;

    public int $twoHandedAttack = 0;

    // Profile
    public string $url;

    public int $totalLoot = 0;

    public int $totalBattles = 0;

    public int $wins = 0;

    public int $losses = 0;

    public int $undecided = 0;

    public int $goldReceived = 0;

    public int $goldLost = 0;

    public int $damageToEnemies = 0;

    public int $damageFromEnemies = 0;
}
