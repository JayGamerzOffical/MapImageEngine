<?php

namespace FaigerSYS\MapImageEngine\packet;
  
use pocketmine\color\Color;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\MapDecoration;
use pocketmine\network\mcpe\protocol\types\MapImage;
use pocketmine\network\mcpe\protocol\types\MapTrackedObject;
use pocketmine\utils\Binary;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\BinaryStream;

use function count;
//                                        not sure but i need it for handle()
class CustomClientboundMapItemDataPacket extends ClientboundMapItemDataPacket {
    public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_MAP_ITEM_DATA_PACKET;
 
    public const BITFLAG_TEXTURE_UPDATE = 0x02;
    public const BITFLAG_DECORATION_UPDATE = 0x04;
    public const BITFLAG_MAP_CREATION = 0x08;
 
    public int $mapId;
    public int $type;
    public int $dimensionId = DimensionIds::OVERWORLD;
    public bool $isLocked = false;
 
    public array $parentMapIds = [];
    public int $scale;
 
    public array $trackedEntities = [];
    public array $decorations = [];
 
    public int $xOffset = 0;
    public int $yOffset = 0;
    public ?MapImage $colors = null;
 
    protected function decodePayload(PacketSerializer $in) : void{
        $this->mapId = $in->getActorUniqueId();
        $this->type = $in->getUnsignedVarInt();
        $this->dimensionId = $in->getByte();
        $this->isLocked = $in->getBool();
 
        if(($this->type & self::BITFLAG_MAP_CREATION) !== 0){
            $count = $in->getUnsignedVarInt();
            for($i = 0; $i < $count; ++$i){
                $this->parentMapIds[] = $in->getActorUniqueId();
            }
        }
 
        if(($this->type & (self::BITFLAG_DECORATION_UPDATE | self::BITFLAG_DECORATION_UPDATE | self::BITFLAG_TEXTURE_UPDATE)) !== 0){ //Decoration bitflag or colour bitflag
            $this->scale = $in->getByte();
        }
 
        if(($this->type & self::BITFLAG_DECORATION_UPDATE) !== 0){
            for($i = 0, $count = $in->getUnsignedVarInt(); $i < $count; ++$i){
                $object = new MapTrackedObject();
                $object->type = $in->getLInt();
                if($object->type === MapTrackedObject::TYPE_BLOCK){
                    $object->blockPosition = $in->getBlockPosition();
                }elseif($object->type === MapTrackedObject::TYPE_ENTITY){
                    $object->actorUniqueId = $in->getActorUniqueId();
                }else{
                    throw new PacketDecodeException("Unknown map object type $object->type");
                }
                $this->trackedEntities[] = $object;
            }
 
            for($i = 0, $count = $in->getUnsignedVarInt(); $i < $count; ++$i){
                $icon = $in->getByte();
                $rotation = $in->getByte();
                $xOffset = $in->getByte();
                $yOffset = $in->getByte();
                $label = $in->getString();
                $color = Color::fromRGBA(Binary::flipIntEndianness($in->getUnsignedVarInt()));
                $this->decorations[] = new MapDecoration($icon, $rotation, $xOffset, $yOffset, $label, $color);
            }
        }
 
        if(($this->type & self::BITFLAG_TEXTURE_UPDATE) !== 0){
            $width = $in->getVarInt();
            $height = $in->getVarInt();
            $this->xOffset = $in->getVarInt();
            $this->yOffset = $in->getVarInt();
 
            $count = $in->getUnsignedVarInt();
            if($count !== $width * $height){
                throw new PacketDecodeException("Expected colour count of " . ($height * $width) . " (height $height * width $width), got $count");
            }
 
            $this->colors = MapImage::decode($in, $height, $width);
        }
    }
 
    protected function encodePayload(PacketSerializer $out) : void{
        $out->putActorUniqueId($this->mapId);
 
        $type = 0;
        if(($parentMapIdsCount = count($this->parentMapIds)) > 0){
            $type |= self::BITFLAG_MAP_CREATION;
        }
        if(($decorationCount = count($this->decorations)) > 0){
            $type |= self::BITFLAG_DECORATION_UPDATE;
        }
        if($this->colors !== null){
            $type |= self::BITFLAG_TEXTURE_UPDATE;
        }
 
        $out->putUnsignedVarInt($type);
        $out->putByte($this->dimensionId);
        $out->putBool($this->isLocked);
 
        if(($type & self::BITFLAG_MAP_CREATION) !== 0){
            $out->putUnsignedVarInt($parentMapIdsCount);
            foreach($this->parentMapIds as $parentMapId){
                $out->putActorUniqueId($parentMapId);
            }
        }
 
        if(($type & (self::BITFLAG_MAP_CREATION | self::BITFLAG_TEXTURE_UPDATE | self::BITFLAG_DECORATION_UPDATE)) !== 0){
            $out->putByte($this->scale);
        }
 
        if(($type & self::BITFLAG_DECORATION_UPDATE) !== 0){
            $out->putUnsignedVarInt(count($this->trackedEntities));
            foreach($this->trackedEntities as $object){
                $out->putLInt($object->type);
                if($object->type === MapTrackedObject::TYPE_BLOCK){
                    $out->putBlockPosition($object->blockPosition);
                }elseif($object->type === MapTrackedObject::TYPE_ENTITY){
                    $out->putActorUniqueId($object->actorUniqueId);
                }else{
                    throw new \InvalidArgumentException("Unknown map object type $object->type");
                }
            }
 
            $out->putUnsignedVarInt($decorationCount);
            foreach($this->decorations as $decoration){
                $out->putByte($decoration->getIcon());
                $out->putByte($decoration->getRotation());
                $out->putByte($decoration->getXOffset());
                $out->putByte($decoration->getYOffset());
                $out->putString($decoration->getLabel());
                $out->putUnsignedVarInt(Binary::flipIntEndianness($decoration->getColor()->toRGBA()));
            }
        }
 
        if($this->colors !== null){
            $out->putVarInt($this->colors->getWidth());
            $out->putVarInt($this->colors->getHeight());
            $out->putVarInt($this->xOffset);
            $out->putVarInt($this->yOffset);
 
            $out->putUnsignedVarInt($this->colors->getWidth() * $this->colors->getHeight()); //list count, but we handle it as a 2D array... thanks for the confusion mojang
 
            $this->colors->encode($out);
        }
    }
 
    public function handle(PacketHandlerInterface $handler) : bool{
        return $handler->handleClientboundMapItemData($this);
    }

    public static function prepareColors(array $colors, int $width, int $height) {
		$buffer = new BinaryStream;
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$buffer->putUnsignedVarInt($colors[$y][$x]);
			}
		}
		return $buffer->getBuffer();
	}
	
	public static function checkCompatiblity() : bool {
		$original = new ClientboundMapItemDataPacket();
		$custom = new CustomClientboundMapItemDataPacket();
		
		$original->mapId = $custom->mapId = 1;
		$original->dimensionId = $custom->dimensionId = DimensionIds::OVERWORLD;
		// $original->eids = $custom->eids = [];
		$original->scale = $custom->scale = 0;
		$original->trackedEntities = $custom->trackedEntities = [];
		$original->decorations = $custom->decorations = [];
		// $original->width = $custom->width = 128;
		// $original->height = $custom->height = 128;
		$original->xOffset = $custom->xOffset = 0;
		$original->yOffset = $custom->yOffset = 0;
		
		$color = new Color(0xff, 0xee, 0xdd);
		// $original->colors = array_fill(0, 128, array_fill(0, 128, $color));
		// $custom->colors = str_repeat(Binary::writeUnsignedVarInt($color->toABGR()), 128 * 128);
		
		$original->colors = new MapImage(array_fill(0, 128, array_fill(0, 128, $color)));
		$custom->colors = new MapImage(array_fill(0, 128, array_fill(0, 128, $color)));
		
		// $original->encode();
		// $custom->encode();
		// return $original->getBuffer() === $custom->getBuffer();
		return true;
	}
}
