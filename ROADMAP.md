# Attack list

Keep a queue (FIFO) of players to be automatically attacked. One can
always add or remove a player from this list.

**Features:**
- Add player to queue
- Remove player from queue
- Remove all players from queue
- View current queue (has pagination)

**Managing the attack list:**

From the main menu the player chooses "Battle". This will present a menu
with the options "farm", "lvlup", "lvldown" and "manage".

- manage: shows queue and offers options to add and remove by id or clear all
- farm, lvlup and lvldown: will find players and offer options to add them to the queue

**Ideas:**
- Implement a game loop so the same app can keep running and attacking automatically

---

**Steps:**

- [ ] Add "Battle" menu option to main menu
- [ ] Fetch lock time (fetch from website)
- [ ] Prevent battle attempts when locked
- [ ] Implement game loop
- [ ] Implement queue mechanism (use DB)
- [ ] Implement "farm", "lvlup" and "lvldown" finders
- [ ] Implement "Add player to queue" mechanism
- [ ] Implement "Remove player from queue"
- [ ] Implement "Clear queue"
- [ ] Implement queue handler

**Queue Handler:**

- [ ] Never attack a player that was already attack within the last X minutes
- [ ] Never attack a player with less than X health points
