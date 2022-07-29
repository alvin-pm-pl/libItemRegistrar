<?php

/* @noinspection PhpUndefinedFieldInspection */
/* @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace alvin0319\libItemRegistrar\task;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\data\bedrock\block\DelegatingBlockStateDeserializer;
use pocketmine\data\bedrock\block\DelegatingBlockStateSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_key_exists;
use function assert;
use function serialize;
use function unserialize;

final class BlockRuntimeIdRegisterTask extends AsyncTask{

	private string $data;

	public function __construct(array $blockIds){
		$this->data = serialize($blockIds);
	}

	public function onRun() : void{
		$data = unserialize($this->data);
		foreach($data as $datum){
			$block = $datum["blockSerialized"];
			$namespace = $datum["namespace"];
			$serializeCallback = $datum["serializeCallback"];
			$deserializeCallback = $datum["deserializeCallback"];
			self::registerBlockToRuntime($block, $namespace, $serializeCallback, $deserializeCallback);
		}
	}

	public static function registerBlockToRuntime(Block $block, string $namespace, ?\Closure $serializeCallback, ?\Closure $deserializeCallback) : void{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();

		assert($serializer instanceof DelegatingBlockStateSerializer);
		assert($deserializer instanceof DelegatingBlockStateDeserializer);

		BlockFactory::getInstance()->register($block, true);

		(function() use ($block, $serializeCallback, $namespace) : void{
			if(isset($this->serializers[$block->getTypeId()])){
				unset($this->serializers[$block->getTypeId()]);
			}
			$this->map($block, $serializeCallback !== null ? $serializeCallback : static fn() => new BlockStateWriter($namespace));
		})->call($serializer->getRealSerializer());

		(function() use ($block, $deserializeCallback, $namespace) : void{
			if(array_key_exists($namespace, $this->deserializeFuncs)){
				unset($this->deserializeFuncs[$namespace]);
			}
			$this->map($namespace, $deserializeCallback !== null ? $deserializeCallback : static fn(BlockStateReader $reader) : Block => clone $block);
		})->call($deserializer->getRealDeserializer());

		$blockStateDictionary = RuntimeBlockMapping::getInstance()->getBlockStateDictionary();
		(function() use ($block, $namespace) : void{
			$cache = $this->stateDataToStateIdLookupCache;
			(function() use ($block, $namespace) : void{
				$this->nameToNetworkIdsLookup[$namespace] = new BlockStateData($namespace, CompoundTag::create(), $block->getStateId());
			})->call($cache);
		})->call($blockStateDictionary);
	}
}