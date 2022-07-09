# libItemRegistrar

A general library-like plugin to manage (custom) items.

# Usage

Overriding pre-existing item (Fishing Rod)

```php
libItemRegistrar::getInstance()->registerItem(new class(new ItemIdentifier(ItemTypeIds::FISHING_ROD), "Fishing Rod") extends Item{
    public function onClickAir(Player $player, Vector3 $directionVector) : ItemUseResult{
	    $player->sendMessage("Hello world!");
	    return ItemUseResult::SUCCESS();
    }
}, libItemRegistrar::getInstance()->getRuntimeIdByName("Fishing Rod"), true); // https://github.com/pmmp/BedrockData/blob/modern-world-support/required_item_list.json#L1843
```