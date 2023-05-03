<?php

/* @noinspection PhpUndefinedFieldInspection */
/* @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace alvin0319\libItemRegistrar;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\convert\ItemTypeDictionaryFromDataHelper;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function file_get_contents;
use function json_decode;
use function str_replace;
use function strtolower;
use const pocketmine\BEDROCK_DATA_PATH;

final class libItemRegistrar extends PluginBase{
	use SingletonTrait;

	/** @var Item[] */
	private array $registeredItems = [];
	/** @var Block[] */
	private array $registeredBlocks = [];

	private int $nextBlockId = BlockTypeIds::FIRST_UNUSED_BLOCK_ID + 1;

	protected function onLoad() : void{
		self::setInstance($this);
	}

	/**
	 * @param Item          $item the Item to register
	 * @param int           $runtimeId the runtime id that will be used by the server to send the item to the player.
	 * This usually can be found using BDS, or included in {@link \pocketmine\BEDROCK_DATA_PATH/required_item_list.json}. for custom items, you should generate this manually.
	 * @param bool          $force
	 * @param string        $namespace the item's namespace. This usually can be found in {@link ItemTypeNames}.
	 * @param \Closure|null $serializeCallback the callback that will be used to serialize the item.
	 * @param \Closure|null $deserializeCallback the callback that will be used to deserialize the item.
	 *
	 * @return void
	 * @see ItemTypeDictionaryFromDataHelper
	 * @see libItemRegistrar::getRuntimeIdByName()
	 */
	public function registerItem(Item $item, int $runtimeId, bool $force = false, string $namespace = "", ?\Closure $serializeCallback = null, ?\Closure $deserializeCallback = null) : void{
		if($serializeCallback !== null){
			/** @phpstan-ignore-next-line */
			Utils::validateCallableSignature(static function(Item $item) : SavedItemData{ }, $serializeCallback);
		}
		if($deserializeCallback !== null){
			Utils::validateCallableSignature(static function(SavedItemData $data) : Item{ }, $deserializeCallback);
		}
		if(isset($this->registeredItems[$item->getTypeId()]) && !$force){
			throw new AssumptionFailedError("Item {$item->getTypeId()} is already registered");
		}
		$this->registeredItems[$item->getTypeId()] = $item;

		StringToItemParser::getInstance()->override($item->getName(), static fn() => clone $item);
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();

		$namespace = $namespace === "" ? "minecraft:" . strtolower(str_replace(" ", "_", $item->getName())) : $namespace;

		// TODO: Closure hack to access ItemSerializer
		// ItemSerializer throws an Exception when we try to register a pre-existing item
		(function() use ($item, $serializeCallback, $namespace) : void{
			$this->itemSerializers[$item->getTypeId()][get_class($item)] = $serializeCallback !== null ? $serializeCallback : static fn() => new SavedItemData($namespace);
		})->call($serializer);
		// TODO: Closure hack to access ItemDeserializer
		// ItemDeserializer throws an Exception when we try to register a pre-existing item
		(function() use ($item, $deserializeCallback, $namespace) : void{
			if(isset($this->deserializers[$item->getName()])){
				unset($this->deserializers[$item->getName()]);
			}
			$this->map($namespace, $deserializeCallback !== null ? $deserializeCallback : static fn(SavedItemData $_) => clone $item);
		})->call($deserializer);

		$dictionary = TypeConverter::getInstance()->getItemTypeDictionary();
		(function() use ($item, $runtimeId, $namespace) : void{
			$this->stringToIntMap[$namespace] = $runtimeId;
			$this->intToStringIdMap[$runtimeId] = $namespace;
			$this->itemTypes[] = new ItemTypeEntry($namespace, $runtimeId, true);
		})->call($dictionary);
	}

	/**
	 * Returns a next item id and increases it.
	 *
	 * @return int
	 * @deprecated Use {@link ItemTypeIds::newId()} instead.
	 */
	public function getNextItemId() : int{
		return ItemTypeIds::newId();
	}

	public function getItemByTypeId(int $typeId) : ?Item{
		return $this->registeredItems[$typeId] ?? null;
	}

	/**
	 * Returns the runtime id of given item name. (only for vanilla items)
	 *
	 * @param string $name
	 *
	 * @return int|null null if runtime id does not exist.
	 */
	public function getRuntimeIdByName(string $name) : ?int{
		static $mappedJson = [];
		if($mappedJson === []){
			$mappedJson = $this->reprocessKeys(json_decode(file_get_contents(Path::join(BEDROCK_DATA_PATH, "required_item_list.json")), true));
		}
		$name = str_replace(" ", "_", strtolower($name));
		return $mappedJson[$name]["runtime_id"] ?? null;
	}

	private function reprocessKeys(array $data) : array{
		$new = [];
		foreach($data as $key => $value){
			$new[str_replace("minecraft:", "", $key)] = $value;
		}
		return $new;
	}
}