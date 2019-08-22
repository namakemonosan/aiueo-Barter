<?php

namespace aieuo\barter;

use pocketmine\Player;
use pocketmine\item\Item;

class ItemDataBase {

    const BARTER_LISTED = 1;
    const BARTER_SOLD = 0;
    const BARTER_RECEIVED = 2;

    /** @var SQLite3 */
    private $db;

    public function __construct(Main $owner) {
        if (!file_exists($owner->getDataFolder()."barter.db")) {
            $this->db = new \SQLite3($owner->getDataFolder()."barter.db", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        } else {
            $this->db = new \SQLite3($owner->getDataFolder()."barter.db", SQLITE3_OPEN_READWRITE);
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS barter (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exhibitor TEXT,
                item1 TEXT,
                item1_count INTEGER,
                item2 TEXT,
                item2_count INTEGER,
                purchaser TEXT,
                barter_state INTEGER,
                created_at TEXT NOT NULL DEFAULT (STRFTIME('%m月%d日 %H時%M分', CURRENT_TIMESTAMP, 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (STRFTIME('%m月%d日 %H時%M分', CURRENT_TIMESTAMP, 'localtime')),
                sold_at TEXT
            );
            CREATE TRIGGER IF NOT EXISTS trigger_updated_at AFTER UPDATE ON barter
            BEGIN
                UPDATE barter SET updated_at = STRFTIME('%m月%d日 %H時%M分', CURRENT_TIMESTAMP, 'localtime') WHERE rowid=NEW.rowid;
            END;
            CREATE TRIGGER IF NOT EXISTS trigger_sold_at AFTER UPDATE OF purchaser ON barter
            BEGIN
                UPDATE barter SET sold_at = STRFTIME('%m月%d日 %H時%M分', CURRENT_TIMESTAMP, 'localtime') WHERE rowid=NEW.rowid;
            END;"
        );
    }

    public function addBarter(Player $exhibitor, Item $item1, Item $item2) {
        $stmt = $this->db->prepare(
            "INSERT INTO barter(
                exhibitor,
                item1,
                item1_count,
                item2,
                item2_count,
                barter_state
            )
            VALUES(
                :exhibitor,
                :item1,
                :item1_count,
                :item2,
                :item2_count,
                :barter_state
            )"
        );
        $stmt->bindValue(":exhibitor", $exhibitor->getName());
        $stmt->bindValue(":item1", $item1->getId().":".$item1->getDamage());
        $stmt->bindValue(":item1_count", $item1->getCount());
        $stmt->bindValue(":item2", $item2->getId().":".$item2->getDamage());
        $stmt->bindValue(":item2_count", $item2->getCount());
        $stmt->bindValue(":barter_state", self::BARTER_LISTED);
        $stmt->execute();
    }

    public function getAllBarters() {
        $result = $this->db->query("SELECT * FROM barter");
        $datas = [];
        while ($row = $result->fetchArray()) {
            $datas[] = $row;
        }
        return $datas;
    }

    public function getAvailableBarters() {
        $result = $this->db->query("SELECT * FROM barter WHERE barter_state=".self::BARTER_LISTED);
        $datas = [];
        while ($row = $result->fetchArray()) {
            $datas[] = $row;
        }
        return $datas;
    }

    public function getBartersFromItem(Item $item1, Item $item2) {
        $stmt = $this->db->prepare("SELECT * FROM barter WHERE item1=:item1 AND item1_count=:item1_count AND item2=:item2 AND item2_count=:item2_count");
        $stmt->bindValue(":item1", $item1->getId().":".$item1->getDamage(), SQLITE3_TEXT);
        $stmt->bindValue(":item1_count", $item1->getCount(), SQLITE3_INTEGER);
        $stmt->bindValue(":item2", $item2->getId().":".$item2->getDamage(), SQLITE3_TEXT);
        $stmt->bindValue(":item2_count", $item2->getCount(), SQLITE3_INTEGER);
        $result = $stmt->execute();
        $datas = [];
        while ($row = $result->fetchArray()) {
            $datas[] = $row;
        }
        return $datas;
    }

    public function getBartersFromExhibitor(Player $exhibitor) {
        $stmt = $this->db->prepare("SELECT * FROM barter WHERE exhibitor=:exhibitor");
        $stmt->bindValue(":exhibitor", $exhibitor->getName());
        $result = $stmt->execute();
        $datas = [];
        while ($row = $result->fetchArray()) {
            $datas[] = $row;
        }
        return $datas;
    }

    public function existsBarter(Item $item1, Item $item2) {
        $stmt = $this->db->prepare("SELECT * FROM barter WHERE item1=:item1 AND item1_count=:item1_count AND item2=:item2 AND item2_count=:item2_count AND barter_state=:barter_state");
        $stmt->bindValue(":item1", $item1->getId().":".$item1->getDamage());
        $stmt->bindValue(":item1_count", $item1->getCount());
        $stmt->bindValue(":item2", $item2->getId().":".$item2->getDamage());
        $stmt->bindValue(":item2_count", $item2->getCount());
        $stmt->bindValue(":barter_state", self::BARTER_LISTED);
        $result = $stmt->execute()->fetchArray();
        return !empty($result);
    }

    public function getSoldBarters(Player $player) {
        $stmt = $this->db->prepare("SELECT * FROM barter WHERE exhibitor=:player AND barter_state=:barter_state");
        $stmt->bindValue(":player", $player->getName());
        $stmt->bindValue(":barter_state", self::BARTER_SOLD);
        $result = $stmt->execute();
        $datas = [];
        while ($row = $result->fetchArray()) {
            $datas[] = $row;
        }
        return $datas;
    }

    public function updateBarter(int $id, Item $item1, Item $item2) {
        $stmt = $this->db->prepare("UPDATE barter SET item1_count=:item1_count, item2_count=:item2_count WHERE id=:id");
        $stmt->bindValue(":item1_count", $item1->getCount());
        $stmt->bindValue(":item2_count", $item2->getCount());
        $stmt->bindValue(":id", $id);
        $stmt->execute();
    }

    public function buy(int $id, Player $purchaser) {
        $stmt = $this->db->prepare("UPDATE barter SET purchaser=:purchaser, barter_state=:barter_state WHERE id=:id");
        $stmt->bindValue(":purchaser", $purchaser->getName());
        $stmt->bindValue(":barter_state", self::BARTER_SOLD);
        $stmt->bindValue(":id", $id);
        $stmt->execute();
    }

    public function finishBarter(int $id) {
        $stmt = $this->db->prepare("UPDATE barter SET barter_state=:barter_state WHERE id=:id");
        $stmt->bindValue(":barter_state", self::BARTER_RECEIVED);
        $stmt->bindValue(":id", $id);
        $stmt->execute();
    }

    public function removeBarter(int $id) {
        $stmt = $this->db->prepare("DELETE FROM barter WHERE id=:id");
        $stmt->bindValue(":id", $id);
        $stmt->execute();
    }
}