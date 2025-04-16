<?php

declare(strict_types=1);

namespace yournamespace\yourplugin;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\item\Item;
use pocketmine\item\Sword;
use pocketmine\item\Pickaxe;
use pocketmine\item\Axe;
use pocketmine\item\Shovel;
use pocketmine\item\Armor;
use pocketmine\item\ArmorType;
use pocketmine\item\enchantment\Enchantment; // Not used for custom *logic*, but good practice
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\utils\TextFormat;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerMoveEvent; // Can be performance intensive
use pocketmine\event\inventory\InventoryTransactionEvent; // Better for armor checks
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private EnchantmentRegistry $enchantmentRegistry;
    private static Main $instance;

    // Store active effects managed by enchants to avoid spamming effect additions
    private array $activeHastePlayers = [];
    private array $activeSpeedPlayers = [];

    public function onLoad(): void {
        self::$instance = $this;
    }

    public function onEnable(): void {
        $this->getLogger()->info(TextFormat::GREEN . "CustomEnchants Enabled!");
        $this->enchantmentRegistry = new EnchantmentRegistry();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // --- Register Example Enchantments ---
        $this->enchantmentRegistry->registerDefaults();

        // Task for periodic checks (like held item / armor effects) - runs every 20 ticks (1 second)
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkHeldItemEffects();
                $this->checkArmorEffects();
            }
        ), 20); // 20 ticks = 1 second
    }

    public function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . "CustomEnchants Disabled!");
    }

    public static function getInstance(): Main {
        return self::$instance;
    }

    public function getEnchantmentRegistry(): EnchantmentRegistry {
        return $this->enchantmentRegistry;
    }

    // --- Command Handling ---

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (strtolower($command->getName()) === "customenchant") {
            if (!$sender->hasPermission("customenchants.command.use")) {
                $sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
                return true;
            }

            if (count($args) < 2) {
                $sender->sendMessage(TextFormat::RED . "Usage: " . $command->getUsage());
                return true;
            }

            $enchantName = strtolower(array_shift($args));
            $levelStr = array_shift($args);
            $targetPlayerName = array_shift($args); // Optional player name

            if (!is_numeric($levelStr)) {
                $sender->sendMessage(TextFormat::RED . "Level must be a number.");
                return true;
            }
            $level = (int) $levelStr;

            $targetPlayer = $sender;
            if ($targetPlayerName !== null) {
                $targetPlayer = $this->getServer()->getPlayerByPrefix($targetPlayerName);
                if (!$targetPlayer instanceof Player) {
                    $sender->sendMessage(TextFormat::RED . "Player '$targetPlayerName' not found.");
                    return true;
                }
            }

            if (!$targetPlayer instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "This command can only be used by players or requires a target player.");
                return true;
            }

            $item = $targetPlayer->getInventory()->getItemInHand();
            if ($item->isNull()) {
                $sender->sendMessage(TextFormat::RED . "You must hold an item in your hand.");
                return true;
            }

            $enchantData = $this->enchantmentRegistry->getEnchantment($enchantName);
            if ($enchantData === null) {
                $sender->sendMessage(TextFormat::RED . "Unknown enchantment: $enchantName");
                $knownEnchants = implode(", ", array_keys($this->enchantmentRegistry->getAllEnchantments()));
                 $sender->sendMessage(TextFormat::GRAY . "Available: " . ($knownEnchants ?: "None"));
                return true;
            }

            if ($level <= 0 || $level > $enchantData['maxLevel']) {
                $sender->sendMessage(TextFormat::RED . "Invalid level for {$enchantData['displayName']}. Max level is {$enchantData['maxLevel']}.");
                return true;
            }

            // Check if applicable (basic check, registry could do more complex checks)
            $applicable = false;
            foreach($enchantData['applicableTypes'] as $typeClass) {
                if(is_a($item, $typeClass, true)) {
                    $applicable = true;
                    break;
                }
            }
            if (!$applicable) {
                 $sender->sendMessage(TextFormat::RED . "Enchantment '{$enchantData['displayName']}' cannot be applied to this item type.");
                 return true;
            }


            $newItem = $this->enchantmentRegistry->addEnchantmentToItem($item, $enchantName, $level);

            if ($newItem === null) {
                // Should not happen if previous checks passed, but good practice
                $sender->sendMessage(TextFormat::RED . "Failed to apply enchantment.");
                return true;
            }

            $targetPlayer->getInventory()->setItemInHand($newItem);
            $sender->sendMessage(TextFormat::GREEN . "Applied {$enchantData['displayName']} {$this->enchantmentRegistry->romanNumerals($level)} to {$targetPlayer->getName()}'s item.");
            if ($targetPlayer !== $sender) {
                $targetPlayer->sendMessage(TextFormat::GREEN . "Your item received {$enchantData['displayName']} {$this->enchantmentRegistry->romanNumerals($level)}!");
            }

            return true;
        }
        return false;
    }

    // --- Event Listeners for Enchantment Effects ---

    /**
     * @priority NORMAL
     * @ignoreCancelled false // Process even if damage is cancelled by other plugins/factors initially
     */
    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event->isCancelled()) {
            //return; // Sometimes you want enchants to trigger even if cancelled (e.g., thorns-like)
                      // But for Lifesteal/Poison, we usually only care if damage *actually* happens.
                      // Let's process cancelled events for now, but check final damage later.
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            $victim = $event->getEntity();

            // --- Offensive Enchants (e.g., Poison, Lifesteal on Sword) ---
            if ($damager instanceof Player) {
                $item = $damager->getInventory()->getItemInHand();
                if (!$item->isNull()) {
                    $enchants = $this->enchantmentRegistry->getEnchantmentsFromItem($item);

                    // Lifesteal Example
                    if (isset($enchants['lifesteal'])) {
                        $level = $enchants['lifesteal'];
                        $chance = 5 * $level; // 5% chance per level
                        if (mt_rand(1, 100) <= $chance && $event->getFinalDamage() > 0) { // Check final damage
                            $healAmount = max(1, $event->getFinalDamage() * (0.1 * $level)); // Heal 10% of damage dealt per level (min 1 HP)
                            $damager->setHealth(min($damager->getMaxHealth(), $damager->getHealth() + $healAmount));
                           // $damager->sendMessage(TextFormat::GREEN."* Lifesteal triggered *"); // Optional feedback
                        }
                    }

                    // Poison Example
                    if (isset($enchants['poison']) && $victim instanceof \pocketmine\entity\Living) {
                        $level = $enchants['poison'];
                        $chance = 8 * $level; // 8% chance per level
                        $duration = 5 * 20 * $level; // 5 seconds per level (in ticks)
                        $amplifier = $level - 1; // Effect level (0 = Poison I, 1 = Poison II)

                        if (mt_rand(1, 100) <= $chance && $event->getFinalDamage() > 0) { // Check final damage
                           $effect = new EffectInstance(VanillaEffects::POISON(), $duration, $amplifier, true); // Visible = true
                           $victim->getEffects()->add($effect);
                          // $damager->sendMessage(TextFormat::DARK_GREEN."* Poison applied *"); // Optional feedback
                        }
                    }
                }
            }
        }

        // --- Defensive Enchants (Could be added here, checking victim's armor) ---
        $victim = $event->getEntity();
        if($victim instanceof Player) {
             // Example: A "Thorns" like custom enchant on armor
             // Example: A "Regeneration" on taking damage enchant on armor
        }
    }

    // --- Check Held Item Effects (Periodic Task) ---
    private function checkHeldItemEffects(): void {
        foreach($this->getServer()->getOnlinePlayers() as $player) {
            $item = $player->getInventory()->getItemInHand();
            $playerName = $player->getName();
            $hasHaste = false;

            if (!$item->isNull()) {
                $enchants = $this->enchantmentRegistry->getEnchantmentsFromItem($item);

                // Haste Example
                if (isset($enchants['haste'])) {
                     // Check if item is applicable (already done by registry definition, but good safety)
                    $enchantData = $this->enchantmentRegistry->getEnchantment('haste');
                    $applicable = false;
                    foreach($enchantData['applicableTypes'] as $typeClass) {
                         if(is_a($item, $typeClass, true)) {
                             $applicable = true;
                             break;
                         }
                     }

                    if ($applicable) {
                        $level = $enchants['haste'];
                        $amplifier = $level -1; // Haste I = amplifier 0
                        $effect = new EffectInstance(VanillaEffects::HASTE(), 40, $amplifier, false); // 2 seconds duration (40 ticks), invisible
                        $player->getEffects()->add($effect);
                        $this->activeHastePlayers[$playerName] = true; // Mark as having haste from enchant
                        $hasHaste = true;
                    }
                }
            }

             // Remove Haste effect if player no longer holds the item and had it from enchant
             if (!$hasHaste && isset($this->activeHastePlayers[$playerName])) {
                 if ($player->getEffects()->has(VanillaEffects::HASTE())) {
                      // Only remove if they don't have Haste from another source (e.g., beacon)
                      // We can't easily distinguish, so we just remove it if they *had* it from the enchant.
                      // A more complex system might track effect sources.
                      $player->getEffects()->remove(VanillaEffects::HASTE());
                 }
                 unset($this->activeHastePlayers[$playerName]);
             }
        }
    }

     // --- Check Armor Effects (Periodic Task) ---
     private function checkArmorEffects(): void {
        foreach($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();
            $hasSpeedBoost = false;
            $maxSpeedLevel = -1; // Track highest level found

            foreach ($player->getArmorInventory()->getContents() as $armor) {
                if (!$armor->isNull() && $armor->equals(ItemFactory::getInstance()->get(ItemIds::LEATHER_BOOTS), false, false) || $armor->equals(ItemFactory::getInstance()->get(ItemIds::CHAINMAIL_BOOTS), false, false) || $armor->equals(ItemFactory::getInstance()->get(ItemIds::IRON_BOOTS), false, false) || $armor->equals(ItemFactory::getInstance()->get(ItemIds::GOLDEN_BOOTS), false, false) || $armor->equals(ItemFactory::getInstance()->get(ItemIds::DIAMOND_BOOTS), false, false) || $armor->equals(ItemFactory::getInstance()->get(ItemIds::NETHERITE_BOOTS), false, false)) { // Check if it's boots
                    $enchants = $this->enchantmentRegistry->getEnchantmentsFromItem($armor);

                    // Speed Boost Example
                    if (isset($enchants['speedboost'])) {
                         $level = $enchants['speedboost'];
                         $maxSpeedLevel = max($maxSpeedLevel, $level -1); // Get highest amplifier (level-1)
                         $hasSpeedBoost = true;
                    }
                }
            }

            if($hasSpeedBoost) {
                $effect = new EffectInstance(VanillaEffects::SPEED(), 40, $maxSpeedLevel, false); // 2 seconds duration, invisible
                $player->getEffects()->add($effect);
                $this->activeSpeedPlayers[$playerName] = true;
            } elseif (isset($this->activeSpeedPlayers[$playerName])) {
                 // Remove speed if they no longer wear enchanted boots
                 if ($player->getEffects()->has(VanillaEffects::SPEED())) {
                     $player->getEffects()->remove(VanillaEffects::SPEED());
                 }
                 unset($this->activeSpeedPlayers[$playerName]);
            }
        }
    }

    /*
    // Alternative Armor Check using InventoryTransactionEvent (more immediate)
    // This is more complex to handle correctly (e.g., swapping items)
    // Periodic check is often simpler and less prone to edge cases.

    public function onTransaction(InventoryTransactionEvent $event) : void{
        $transaction = $event->getTransaction();
        $player = $transaction->getSource();

        foreach($transaction->getActions() as $action){
            if($action instanceof SlotChangeAction){
                $inventory = $action->getInventory();
                if($inventory instanceof \pocketmine\inventory\ArmorInventory){
                    // Item taken off
                    $oldItem = $action->getSourceItem();
                    $this->checkArmorPieceRemoved($player, $oldItem);

                    // Item put on
                    $newItem = $action->getTargetItem();
                     $this->checkArmorPieceAdded($player, $newItem);
                }
            }
        }
    }

    private function checkArmorPieceAdded(Player $player, Item $item): void {
       // Check $item for your enchant and apply effect if needed
       // Be careful here: Need to check *all* armor pieces again
       // because player might still have another piece with the same enchant.
       // This makes the periodic check simpler overall.
    }

     private function checkArmorPieceRemoved(Player $player, Item $item): void {
         // Check $item for your enchant
         // If found, check if *any other* armor piece still has the enchant.
         // If not, remove the effect.
         // This makes the periodic check simpler overall.
     }
    */

}
