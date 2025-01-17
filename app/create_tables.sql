CREATE TABLE IF NOT EXISTS player (
    id INT NOT NULL PRIMARY KEY,
    name TEXT NOT NULL,
    level INT NOT NULL,
    alignment INT NOT NULL,
    currentHP DOUBLE NOT NULL,
    maxHP INT NOT NULL,
    experience INT NOT NULL,
    createdAt DATETIME,
    strength INT NOT NULL,
    stamina INT NOT NULL,
    dexterity INT NOT NULL,
    fightingAbility INT NOT NULL,
    parry INT NOT NULL,
    armour INT NOT NULL,
    oneHandedAttack INT NOT NULL,
    twoHandedAttack INT NOT NULL,
    url TEXT NOT NULL,
    totalLoot INT NOT NULL,
    totalBattles INT NOT NULL,
    wins INT NOT NULL,
    losses INT NOT NULL,
    undecided INT NOT NULL,
    goldReceived INT NOT NULL,
    goldLost INT NOT NULL,
    damageToEnemies INT NOT NULL,
    damageFromEnemies INT NOT NULL
);

CREATE TABLE IF NOT EXISTS battle_report (
    battleId INT NOT NULL PRIMARY KEY,
    attackerId INT NOT NULL,
    victimId INT NOT NULL,
    winnerId INT,
    `date` DATETIME
);
