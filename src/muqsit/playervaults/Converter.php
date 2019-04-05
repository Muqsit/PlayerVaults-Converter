<?php

declare(strict_types=1);

namespace muqsit\playervaults;

use muqsit\playervaults\PlayerVaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\nbt\BigEndianNBTStream;
use pocketmine\nbt\tag\ListTag;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;

class Converter extends PluginBase{

	/** @var BigEndianNBTStream */
	private $serializer;

	public function onEnable() : void{
		if($this->getPlayerVaults() === null){
			throw new \RuntimeException("Could not find plugin PlayerVaults");
		}

		$this->serializer = new BigEndianNBTStream();
	}

	public function getPlayerVaults() : ?PlayerVaults{
		return $this->getServer()->getPluginManager()->getPlugin("PlayerVaults");
	}

	public function convert(string $inventory) : string{
		$nbt = $this->serializer->readCompressed($inventory);
		$tag = $nbt->getListTag("ItemList");
		$nbt->removeTag("ItemList");
		$nbt->setTag(new ListTag("Inventory", $tag->getValue()));
		return $this->serializer->writeCompressed($nbt);
	}

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args) : bool{
		if(isset($args[0])){
			switch($args[0]){
				case "mysql":
					if(isset($args[1], $args[2], $args[3], $args[4])){
						$logger = $this->getLogger();

						$logger->info("Connecting to the MySQL server...");
						try{
							$mysql = new \mysqli($args[1], $args[2], $args[3], $args[4], (int) ($args[5] ?? ini_get("mysqli.default_port")));
						}catch(\Exception $e){
							$logger->info("Failed to connect to the MySQL server: " . $e->getMessage());
							return false;
						}

						$stmt = $mysql->prepare("SHOW TABLES LIKE 'playervaults'");
						$stmt->execute();
						$exists = $stmt->fetch();
						$stmt->close();

						if($exists){
							$stmt = $mysql->prepare("SHOW COLUMNS FROM playervaults LIKE 'inventory'");
							$stmt->execute();
							$exists = $stmt->fetch();
							$stmt->close();

							if($exists){
								$stmt = $mysql->prepare("CREATE TABLE IF NOT EXISTS vaults_old LIKE playervaults");
								$stmt->execute();
								$stmt->close();

								$stmt = $mysql->prepare("INSERT INTO vaults_old SELECT * FROM playervaults");
								$stmt->execute();
								$stmt->close();

								$stmt = $mysql->prepare("SELECT player, number, inventory FROM playervaults");
								$stmt->bind_result($player, $number, $inventory);
								$stmt->execute();
								$rows = [];
								while($stmt->fetch()){
									$rows[] = [$player, $number, $inventory];
								}
								$stmt->close();

								$progress = 0;
								$total = count($rows);

								$stmt = $mysql->prepare("UPDATE playervaults SET inventory=? WHERE player=? AND number=?");
								$stmt->bind_param("ssi", $inventory, $player, $number);
								foreach($rows as [$player, $number, $inventory]){
									$inventory = $this->convert($inventory);
									$stmt->execute();
									$logger->info("[" . ++$progress . "/" . $total . "] Converted " . $player . "(#" . $number . ")'s vault data");
								}
								$stmt->close();

								$stmt = $mysql->prepare("SHOW TABLES LIKE 'vaults'");
								$stmt->execute();
								$exists = $stmt->fetch();
								$stmt->close();

								if(!$exists){
									$stmt = $mysql->prepare("ALTER TABLE playervaults CHANGE COLUMN inventory data BLOB NOT NULL");
									$stmt->execute();
									$stmt->close();

									$stmt = $mysql->prepare("RENAME TABLE playervaults TO vaults");
									$stmt->execute();
									$stmt->close();
								}else{
									$stmt = $mysql->prepare("INSERT INTO vaults SELECT player, number, inventory FROM playervaults");
									$stmt->execute();
									$stmt->close();

									$stmt = $mysql->prepare("DROP TABLE playervaults");
									$stmt->execute();
									$stmt->close();
								}

								$logger->info("Conversion completed!");
							}else{
								$logger->info("Nothing to convert!");
							}

							$mysql->close();
						}else{
							$logger->info("Nothing to convert!");
						}
						return true;
					}
					break;
			}
		}

		$sender->sendMessage(implode(TF::EOL, [
			"/" . $label . " mysql <host> <username> <password> <dbname> [port] - Convert an old mysql PlayerVaults db"
		]));
		return false;
	}
}