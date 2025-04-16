<?php

declare(strict_types=1);

namespace yournamespace\yourplugin;

use pocketmine\item\Item;
use pocketmine\item\Sword;
use pocketmine\item\Pickaxe;
use pocketmine\item\Axe;
use pocketmine\item\Shovel;
use pocketmine\item\Armor;
use pocketmine\item\ArmorType;
use pocketmine\item\Tool;
use pocketmine\item\Durable;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\TextFormat;
use pocketmine\data\bedrock\EnchantmentIdMap; // Useful for colors maybe

class EnchantmentRegistry {

    // [ 'enchant_id' => ['displayName' => 'Nice Name', 'maxLevel' => 3, 'applicableTypes' => [Sword::class], 'loreColor' => TextFormat::GRAY] ]
    private array $registeredEnchantments = [];

    public function registerDefaults(): void {
        $this->registerEnchantment(
            'poison',
            'Poison',
            3, // Max Level
            [Sword::class], // Applicable Items
            TextFormat::DARK_GREEN // Lore Color
        );
        $this->registerEnchantment(
            'lifesteal',
            'Lifesteal',
            3,
            [Sword::class],
            TextFormat::RED
        );
         $this->registerEnchantment(
            'haste',
            'Haste',
            3,
            [Pickaxe::class, Axe::class, Shovel::class], // Tools that benefit from haste
            TextFormat::YELLOW
        );
        $this->registerEnchantment(
             'speedboost',
             'Speed Boost',
             2,
             // Only Boots (Need to check Item ID or Type specifically in listener/task)
             // For now, let's make it generally applicable to Armor and check type later.
             // Or even better, make a specific Boots class check if possible.
             // Using Armor::class and checking ArmorType::BOOTS is best.
             [Armor::class],
             TextFormat::AQUA
         );
    }

    public function registerEnchantment(string $id, string $displayName, int $maxLevel, array $applicableTypes, string $loreColor = TextFormat::GRAY): bool {
        $id = strtolower($id);
        if (isset($this->registeredEnchantments[$id])) {
            return false; // Already registered
        }
        $this->registeredEnchantments[$id] = [
            'displayName' => $displayName,
            'maxLevel' => $maxLevel,
            'applicableTypes' => $applicableTypes, // Array of class names (e.g., Sword::class)
            'loreColor' => $loreColor
        ];
        return true;
    }

    public function getEnchantment(string $id): ?array {
        return $this->registeredEnchantments[strtolower($id)] ?? null;
    }

     public function getAllEnchantments(): array {
        return $this->registeredEnchantments;
    }

    public function formatEnchantmentLore(string $id, int $level): ?string {
        $enchantData = $this->getEnchantment($id);
        if ($enchantData === null) {
            return null;
        }
        $romanLevel = $this->romanNumerals($level);
        // Reset ensures previous colors don't affect it, Color applies, Name, Space, Roman Numeral
        return TextFormat::RESET . $enchantData['loreColor'] . $enchantData['displayName'] . " " . $romanLevel;
    }

    public function addEnchantmentToItem(Item $item, string $id, int $level): ?Item {
        $enchantData = $this->getEnchantment($id);
        if ($enchantData === null || $level <= 0 || $level > $enchantData['maxLevel']) {
            return null; // Invalid enchant or level
        }

        $loreLine = $this->formatEnchantmentLore($id, $level);
        if ($loreLine === null) return null; // Should not happen

        $lore = $item->getLore();
        $enchantIdentifier = TextFormat::clean($enchantData['displayName']); // Use clean name for searching lore

        // Remove existing version of this enchant first
        $newLore = [];
        foreach ($lore as $line) {
            if (str_contains(TextFormat::clean($line), $enchantIdentifier)) {
                 continue; // Skip old version
            }
            $newLore[] = $line;
        }

        // Add the new enchantment lore
        $newLore[] = $loreLine;

        $item->setLore($newLore);

        // Optional: Add a custom NBT tag to make parsing easier/more reliable than lore sometimes
        // $nbt = $item->getNamedTag();
        // $customEnchantsTag = $nbt->getListTag("CustomEnchants") ?? new ListTag([], NBT::TAG_Compound);
        // $found = false;
        // foreach ($customEnchantsTag as $key => $tag) {
        //     if ($tag instanceof CompoundTag && $tag->getString("id", "") === $id) {
        //         $tag->setShort("lvl", $level);
        //         $found = true;
        //         break;
        //     }
        // }
        // if (!$found) {
        //     $customEnchantsTag->push(new CompoundTag([
        //         new StringTag("id", $id),
        //         new ShortTag("lvl", $level)
        //     ]));
        // }
        // $nbt->setTag("CustomEnchants", $customEnchantsTag);
        // $item->setNamedTag($nbt); // If using NBT

        return $item;
    }

    public function getEnchantmentsFromItem(Item $item): array {
        $enchants = [];
        $lore = $item->getLore();

        foreach ($this->registeredEnchantments as $id => $data) {
            $enchantIdentifier = TextFormat::clean($data['displayName']); // Clean name for matching
            foreach ($lore as $line) {
                $cleanLine = TextFormat::clean($line); // Clean lore line
                if (str_starts_with($cleanLine, $enchantIdentifier)) {
                    // Attempt to extract level (assuming format "EnchantName RomanNumeral")
                    $parts = explode(' ', $cleanLine);
                    $romanNumeral = end($parts);
                    $level = $this->romanToInteger($romanNumeral);
                    if ($level > 0) {
                        $enchants[$id] = $level;
                        break; // Found this enchant, move to next registered enchant
                    }
                }
            }
        }

        // If using NBT:
        // $nbt = $item->getNamedTag();
        // $customEnchantsTag = $nbt->getListTag("CustomEnchants");
        // if ($customEnchantsTag !== null) {
        //     foreach ($customEnchantsTag as $tag) {
        //         if ($tag instanceof CompoundTag) {
        //             $id = $tag->getString("id", "");
        //             $lvl = $tag->getShort("lvl", 0);
        //             if ($id !== "" && $lvl > 0 && isset($this->registeredEnchantments[$id])) {
        //                 $enchants[$id] = $lvl;
        //             }
        //         }
        //     }
        // }

        return $enchants;
    }


    // --- Roman Numeral Helpers ---

    public function romanNumerals(int $number): string {
        if ($number < 1) return ""; // Or handle as error
        $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $returnValue = '';
        while ($number > 0) {
            foreach ($map as $roman => $int) {
                if ($number >= $int) {
                    $number -= $int;
                    $returnValue .= $roman;
                    break;
                }
            }
        }
        return $returnValue;
    }

     public function romanToInteger(string $roman): int {
        $roman = strtoupper($roman);
        $map = ['M' => 1000, 'D' => 500, 'C' => 100, 'L' => 50, 'X' => 10, 'V' => 5, 'I' => 1];
        $result = 0;
        $prevValue = 0;

        for ($i = strlen($roman) - 1; $i >= 0; $i--) {
            $currentValue = $map[$roman[$i]] ?? 0;
            if ($currentValue === 0) return 0; // Invalid character

            if ($currentValue < $prevValue) {
                $result -= $currentValue;
            } else {
                $result += $currentValue;
            }
            $prevValue = $currentValue;
        }
        return $result > 0 ? $result : 0; // Return 0 if result is negative (invalid sequence)
    }
}
