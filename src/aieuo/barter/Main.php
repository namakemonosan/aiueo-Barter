<?php

namespace aieuo\barter;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\item\Item;
use pocketmine\event\player\PlayerJoinEvent;

class Main extends PluginBase implements Listener {

    /** @var ItemDataBase */
    private $db;

    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->db = new ItemDataBase($this);
    }

    public function join(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $barters = $this->db->getSoldBarters($player);
        $count = 0;
        foreach ($barters as $barter) {
            $id = explode(":", $barter["item2"]);
            $item = Item::get((int)$id[0], (int)$id[1] ?? 0, (int)$barter["item2_count"]);

            if (!$player->getInventory()->canAddItem($item)) continue;
            $player->getInventory()->addItem($item);
            $this->db->finishBarter($barter["id"]);
            $count ++;
        }
        if ($count > 0) $player->sendMessage("[Barter] 成立した交換".count($barters)."件中".$count."件受け取りました");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        if (!$command->testPermission($sender)) return true;
        $form = [
            "type" => "form",
            "title" => "選択",
            "content" => "§7ボタンを押してください",
            "buttons" => [
                ["text" => "交換募集する"],
                ["text" => "交換する"],
                ["text" => "交換募集したもの"],
                ["text" => "終了"]
            ]
        ];
        $this->sendForm($sender, $form, [$this, "onMenu"]);
        return true;
    }

    public function onMenu($player, $data, $message = "§7色々記入してください§f", $default = [], $errorPlaces = []) {
        if ($data === null) return;
        switch ($data) {
            case 0:
                $form = [
                    "type" => "custom_form",
                    "title" => "募集",
                    "content" => [
                        [
                            "type" => "label",
                            "text" => $message,
                        ],
                        [
                            "type" => "input",
                            "text" => (in_array(1, $errorPlaces) ? "§e" : "")."交換するアイテムID§f",
                            "default" => $default[1] ?? "",
                        ],
                        [
                            "type" => "input",
                            "text" => (in_array(2, $errorPlaces) ? "§e" : "")."交換するアイテム数§f",
                            "placeholder" => "1以上の数字で",
                            "default" => $default[2] ?? "",
                        ],
                        [
                            "type" => "input",
                            "text" => (in_array(3, $errorPlaces) ? "§e" : "")."ほしいアイテムID§f",
                            "default" => $default[3] ?? "",
                        ],
                        [
                            "type" => "input",
                            "text" => (in_array(4, $errorPlaces) ? "§e" : "")."ほしいアイテム数§f",
                            "placeholder" => "1以上の数字で",
                            "default" => $default[4] ?? "",
                        ],
                        [
                            "type" => "toggle",
                            "text" => "<キャンセル>"
                        ],
                    ]
                ];
                $this->sendForm($player, $form, [$this, "onSell"]);
                break;
            case 1:
                $barters = $this->db->getAvailableBarters();
                $buttons = [];
                foreach ($barters as $barter) {
                    $id1 = explode(":", $barter["item1"]);
                    $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
                    $id2 = explode(":", $barter["item2"]);
                    $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);

                    $buttons[] = ["text" => $barter["item1"]."(".$item1->getName().") x".$barter["item1_count"]." <-> ".$barter["item2"]."(".$item2->getName().") x".$barter["item2_count"]];
                }
                $form = [
                    "type" => "form",
                    "title" => "交換する",
                    "content" => empty($buttons) ? "まだ募集はないようです" : "§7貰うアイテム <-> 渡すアイテム",
                    "buttons" => $buttons,
                ];
                $this->sendForm($player, $form, [$this, "onBuy"], $barters);
                break;
            case 2:
                $barters = $this->db->getBartersFromExhibitor($player);
                usort($barters, function ($a, $b) {
                    if ($a["barter_state"] == $b["barter_state"]) return 0;
                    return ($a["barter_state"] < $b["barter_state"]) ? -1 : 1;
                });
                $buttons = [];
                foreach ($barters as $barter) {
                    switch ($barter["barter_state"]) {
                        case ItemDataBase::BARTER_LISTED:
                            $state = "§e募集中";
                            break;
                        case ItemDataBase::BARTER_SOLD:
                            $state = "§b受け取り待ち";
                            break;
                        case ItemDataBase::BARTER_RECEIVED:
                            $state = "§7完了";
                            break;
                        default:
                            $state = "§cエラー";
                    }
                    $id1 = explode(":", $barter["item1"]);
                    $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
                    $id2 = explode(":", $barter["item2"]);
                    $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);

                    $buttons[] = [
                        "text" => $barter["item2"]." x".$barter["item2_count"]." <-> ".$barter["item1"]." x".$barter["item1_count"]." | ".$state
                    ];
                }
                $form = [
                    "type" => "form",
                    "title" => "あなたの募集一覧",
                    "content" => empty($buttons) ? "まだ募集はないようです" : "§7貰うアイテム <-> 渡すアイテム | 状態",
                    "buttons" => $buttons,
                ];
                $this->sendForm($player, $form, [$this, "onBarterList"], $barters, $item1, $item2);
                break;
        }
    }

    public function onSell($player, $data) {
        if ($data === null) return;

        if ($data[5]) {
            $form = [
                "type" => "form",
                "title" => "選択",
                "content" => "§7ボタンを押してください",
                "buttons" => [
                    ["text" => "交換募集"],
                    ["text" => "交換する"],
                    ["text" => "交換募集したもの"],
                    ["text" => "終了"]
                ]
            ];
            $this->sendForm($player, $form, [$this, "onMenu"]);
            return;
        }

        if ($data[1] === "" or $data[2] === "" or $data[3] === "" or $data[4] === "") {
            $this->onMenu($player, 0, "§c必要事項を記入してください§f", $data);
            return;
        }

        $errors = [];
        $errorPlaces = [];
        try {
            $item1 = Item::fromString($data[1]);
        } catch (\InvalidArgumentException $e) {
            $errors[0] = "§cその名前のアイテムは見つかりません§f";
            $errorPlaces[] = 1;
        }
        try {
            $item2 = Item::fromString($data[3]);
        } catch (\InvalidArgumentException $e) {
            $errors[0] = "§cその名前のアイテムは見つかりません§f";
            $errorPlaces[] = 3;
        }
        if ((int)$data[2] <= 0) {
            $errors[1] = "§c個数は1以上の半角数字で入力してください§f";
            $errorPlaces[] = 2;
        }
        if ((int)$data[4] <= 0) {
            $errors[1] = "§c個数は1以上の半角数字で入力してください§f";
            $errorPlaces[] = 4;
        }
        if (!empty($errors)) {
            $this->onMenu($player, 0, implode("\n", $errors), $data, $errorPlaces);
            return;
        }

        $item1->setCount((int)$data[2]);
        $item2->setCount((int)$data[4]);

        if (!$player->getInventory()->contains($item1)) {
            $this->onMenu($player, 0, "§c必要なアイテムを持っていません§f", $data);
            return;
        }

        if ($this->db->existsBarter($item2, $item1)) {
            $form = [
                "type" => "modal",
                "title" => "交換",
                "content" => "条件にあう募集が既にあります。\n交換しますか?",
                "button1" => "はい",
                "button2" => "いいえ",
            ];
            $this->sendForm($player, $form, [$this, "onConfirmBarter"], $item1, $item2);
            return;
        }

        $player->getInventory()->removeItem($item1);
        $this->db->addBarter($player, $item1, $item2);
        $player->sendMessage("募集しました");
    }

    public function onConfirmBarter($player, $data, $item1, $item2) {
        if ($data === null) return;

        if (!$data) {
            $player->getInventory()->removeItem($item1);
            $this->db->addBarter($player, $item1, $item2);
            $player->sendMessage("募集しました");
            return;
        }

        $barter = $this->db->getBartersFromItem($item2, $item1)[0];

        $player->getInventory()->removeItem($item1);
        if (!$player->getInventory()->canAddItem($item2)) {
            $player->getInventory()->addItem($item1);
            $player->sendMessage("インベントリがいっぱいです");
            return;
        }

        $player->getInventory()->addItem($item2);
        $this->db->buy($barter["id"], $player);
        $player->sendMessage("交換しました");
    }

    public function onBuy($player, $data, $barters) {
        if ($data === null) return;
        $barter = $barters[$data];

        $id1 = explode(":", $barter["item1"]);
        $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
        $id2 = explode(":", $barter["item2"]);
        $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);

        $label = $barter["item1"]."(".$item1->getName().")を".$barter["item1_count"]."個貰って ".$barter["item2"]."(".$item2->getName().")を".$barter["item2_count"]."個渡す";
        $label .= "\n募集者: ".$barter["exhibitor"];
        $label .= "\n最終更新: ".$barter["updated_at"];
        if (!$player->getInventory()->contains($item2)) {
            $label .= "\n§e交換対象のアイテムを所持していません";
        }
        $form = [
            "type" => "custom_form",
            "title" => $barter["item1"]."(".$item1->getName().") x".$barter["item1_count"]." <-> ".$barter["item2"]."(".$item2->getName().") x".$barter["item2_count"],
            "content" => [
                [
                    "type" => "label",
                    "text" => $label,
                ],
                [
                    "type" => "toggle",
                    "text" => "交換する",
                ],
                [
                    "type" => "toggle",
                    "text" => "<戻る>",
                ]
            ],
        ];
        $this->sendForm($player, $form, [$this, "onBarterDetail"], $barter);
    }

    public function onBarterDetail($player, $data, $barter) {
        if ($data === null) return;
        if ($data[2]) {
            $this->onMenu($player, 1);
            return;
        }
        if (!$data[1]) return;

        $id1 = explode(":", $barter["item1"]);
        $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
        $id2 = explode(":", $barter["item2"]);
        $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);

        if (!$player->getInventory()->contains($item2)) {
            $player->sendMessage("必要なアイテムを持っていません");
            return;
        }

        $player->getInventory()->removeItem($item2);
        if (!$player->getInventory()->canAddItem($item1)) {
            $player->getInventory()->addItem($item2);
            $player->sendMessage("インベントリがいっぱいです");
            return;
        }

        $player->getInventory()->addItem($item1);
        $this->db->buy($barter["id"], $player);
        $player->sendMessage("交換しました");
    }

    public function onBarterList($player, $data, $barters, $item1, $item2) {
        if ($data === null) return;
        $barter = $barters[$data];

        switch ($barter["barter_state"]) {
            case ItemDataBase::BARTER_LISTED:
                $state = "§e交換募集中";
                $toggle = "募集編集";
                break;
            case ItemDataBase::BARTER_SOLD:
                $state = "§b受け取り待ち";
                $toggle = "アイテムを受け取る";
                break;
            case ItemDataBase::BARTER_RECEIVED:
                $state = "§7完了";
                $toggle = "削除する";
                break;
        }
        $purchaser = $barter["purchaser"] ?? "-";
        $sold_at = $barter["sold_at"] ?? "-";
        $form = [
            "type" => "custom_form",
            "title" => $barter["item2"]."(".$item2->getName().") x".$barter["item2_count"]." <-> ".$barter["item1"]."(".$item1->getName().") x".$barter["item1_count"],
            "content" => [
                [
                    "type" => "label",
                    "text" => $barter["item2"]."(".$item2->getName().")を".$barter["item2_count"]."個貰って ".$barter["item1"]."(".$item1->getName().")を".$barter["item1_count"]."個渡す"."\n".
                                "募集日: ".$barter["created_at"]."\n".
                                "状態: ".$state."§f\n".
                                "交換相手: ".$purchaser."\n".
                                "交換日: ".$sold_at,
                ],
                [
                    "type" => "toggle",
                    "text" => $toggle,
                ],
                [
                    "type" => "toggle",
                    "text" => "<戻る>",
                ]
            ],
        ];
        $this->sendForm($player, $form, [$this, "onSelectBarter"], $barter);
    }

    public function onSelectBarter($player, $data, $barter) {
        if ($data === null) return;
        if ($data[2]) {
            $this->onMenu($player, 2);
            return;
        }
        if (!$data[1] or $barter["exhibitor"] !== $player->getName()) return;

        switch ($barter["barter_state"]) {
            case ItemDataBase::BARTER_LISTED:
                $form = $this->getEditForm($barter);
                $this->sendForm($player, $form, [$this, "onEdit"], $barter);
                break;
            case ItemDataBase::BARTER_SOLD:
                $id = explode(":", $barter["item2"]);
                $item = Item::get((int)$id[0], (int)$id[1] ?? 0, (int)$barter["item2_count"]);
                if (!$player->getInventory()->canAddItem($item)) {
                    $player->sendMessage("インベントリがいっぱいなので受け取れません");
                    break;
                }
                $player->getInventory()->addItem($item);
                $this->db->finishBarter($barter["id"]);
                $player->sendMessage("受け取りました");
                break;
            case ItemDataBase::BARTER_RECEIVED:
                $this->db->removeBarter($barter["id"]);
                $player->sendMessage("削除しました");
                break;
        }
    }

    public function getEditForm($barter, $error = "", $default = [], $errorPlaces = []) {
        $id1 = explode(":", $barter["item1"]);
        $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
        $id2 = explode(":", $barter["item2"]);
        $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);
        $message = $barter["item2"]."(".$item2->getName().")を".$barter["item2_count"]."個貰って ".$barter["item1"]."(".$item1->getName().")を".$barter["item1_count"]."個渡す\n";

        $form = [
            "type" => "custom_form",
            "title" => "編集",
            "content" => [
                [
                    "type" => "label",
                    "text" => $message.$error,
                ],
                [
                    "type" => "input",
                    "text" => (in_array(1, $errorPlaces) ? "§e" : "")."渡すアイテム(".$item1->getName().")の数§f",
                    "placeholder" => "1以上の数字で",
                    "default" => $default[1] ?? (string)$barter["item1_count"],
                ],
                [
                    "type" => "input",
                    "text" => (in_array(2, $errorPlaces) ? "§e" : "")."貰うアイテム(".$item2->getName().")の数§f",
                    "placeholder" => "1以上の数字で",
                    "default" => $default[2] ?? (string)$barter["item2_count"],
                ],
                [
                    "type" => "toggle",
                    "text" => "募集キャンセル"
                ],
                [
                    "type" => "toggle",
                    "text" => "<戻る>"
                ],
            ]
        ];
        return $form;
    }

    public function onEdit($player, $data, $barter) {
        if ($data === null) return;
        if ($data[4]) {
            $this->onMenu($player, 2);
            return;
        }

        $id1 = explode(":", $barter["item1"]);
        $item1 = Item::get((int)$id1[0], (int)$id1[1] ?? 0, (int)$barter["item1_count"]);
        $id2 = explode(":", $barter["item2"]);
        $item2 = Item::get((int)$id2[0], (int)$id2[1] ?? 0, (int)$barter["item2_count"]);
        if ($data[3]) {
            if (!$player->getInventory()->canAddItem($item1)) {
                $player->sendMessage("インベントリがいっぱいなのでキャンセルできません");
                return;
            }
            $player->getInventory()->addItem($item1);

            $this->db->removeBarter($barter["id"]);
            $player->sendMessage("募集キャンセルしました");
            return;
        }

        $errors = [];
        $errorPlaces = [];
        if ((int)$data[1] <= 0) {
            $errors[0] = "§c個数は1以上の半角数字で入力してください§f";
            $errorPlaces[] = 1;
        }
        if ((int)$data[2] <= 0) {
            $errors[0] = "§c個数は1以上の半角数字で入力してください§f";
            $errorPlaces[] = 2;
        }
        if (empty($errors)) {
            $diff = (int)$data[1] - $item1->getCount();
            if ($diff > 0) {
                $item1->setCount($diff);
                if (!$player->getInventory()->contains($item1)) {
                    $errors[1] = "§c必要なアイテムを持っていません§f";
                    $errorPlaces[] = 1;
                } else {
                    $player->getInventory()->removeItem($item1);
                }
            } elseif ($diff < 0) {
                $item1->setCount(-$diff);
                if (!$player->getInventory()->canAddItem($item1)) {
                    $errors[1] = "§cインベントリがいっぱいです§f";
                    $errorPlaces[] = 1;
                } else {
                    $player->getInventory()->addItem($item1);
                }
            }
            $item1->setCount((int)$data[1]);
            $item2->setCount((int)$data[2]);
        }
        if (!empty($errors)) {
            $form = $this->getEditForm($barter, implode("\n", $errors), $data, $errorPlaces);
            $this->sendForm($player, $form, [$this, "onEdit"], $barter);
            return;
        }

        $this->db->updateBarter($barter["id"], $item1, $item2);
        $player->sendMessage("編集しました");
    }






    public function encodeJson($data){
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        return $json;
    }

    public function sendForm($player, $form, $callable = null, ...$datas) {
        while (true) {
            $id = mt_rand(0, 999999999);
            if (!isset($this->forms[$id])) break;
        }
        $this->forms[$id] = [$callable, $datas];
        $pk = new ModalFormRequestPacket();
        $pk->formId = $id;
        $pk->formData = $this->encodeJson($form);
        $player->dataPacket($pk);
    }

    public function receive(DataPacketReceiveEvent $event){
        $pk = $event->getPacket();
        $player = $event->getPlayer();
        if ($pk instanceof ModalFormResponsePacket) {
            if (isset($this->forms[$pk->formId])) {
                $json = str_replace([",]",",,"], [",\"\"]",",\"\","], $pk->formData);
                $data = json_decode($json);
                if (is_callable($this->forms[$pk->formId][0])) {
                    call_user_func_array($this->forms[$pk->formId][0], array_merge([$player, $data], $this->forms[$pk->formId][1]));
                }
                unset($this->forms[$pk->formId]);
            }
        }
    }
}
