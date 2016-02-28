<?php

namespace LoginSystem;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ServerScheduler;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\Command;

use pocketmine\level\Level;
use pocketmine\level\sound\BatSound;
use pocketmine\level\sound\PopSound;
use pocketmine\level\sound\LaunchSound;
use pocketmine\level\Position;
use pocketmine\math\Vector3;

use AuthMePE\Task;
use AuthMePE\Task2;
use AuthMePE\SessionTask;
use AuthMePE\SoundTask;
use AuthMePE\UnbanTask;
use AuthMePE\TokenDeleteTask;

use AuthMePE\BaseEvent;

use AuthMePE\PlayerAuthEvent;
use AuthMePE\PlayerLogoutEvent;
use AuthMePE\PlayerRegisterEvent;
use AuthMePE\PlayerUnregisterEvent;
use AuthMePE\PlayerChangePasswordEvent;
use AuthMePE\PlayerAddEmailEvent;
use AuthMePE\PlayerLoginTimeoutEvent;
use AuthMePE\PlayerAuthSessionStartEvent;
use AuthMePE\PlayerAuthSessionExpireEvent;

use specter\network\SpecterPlayer;

class AuthMePE extends PluginBase implements Listener{
	
	private $login = array();
	private $session = array();
	private $bans = array();
	
	private $token_generated = null;
	
	private $specter = false;
	
	const VERSION = "0.1.4";
	
	public function getInstance(){
	  return $this;
	}
	
	public function onEnable(){
		$sa = $this->getServer()->getPluginManager()->getPlugin("SimpleAuth");
		if($sa !== null){
			$this->getLogger()->notice("SimpleAuth has been disabled as it's a conflict plugin");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		if(!is_dir($this->getDataFolder())){
		  mkdir($this->getDataFolder());
		}
		$this->saveDefaultConfig();
	  $this->cfg = $this->getConfig();
	  $this->reloadConfig();
		if(!is_dir($this->getDataFolder()."data")){
			mkdir($this->getDataFolder()."data");
		}
		$this->data = new Config($this->getDataFolder()."data/data.yml", Config::YAML, array());
		$this->ip = new Config($this->getDataFolder()."data/ip.yml", Config::YAML);
		$this->specter = false; //Force false
		$sp = $this->getServer()->getPluginManager()->getPlugin("Specter");
		if($sp !== null){
			$this->getServer()->getLogger()->info("Loaded with Specter!");
			$this->specter = true;
		}
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new Task($this), 20 * 3);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		if($this->getServer()->getPluginManager()->isPluginEnabled($this) !== true){
		  $this->getLogger()->notice("");
		  $this->getServer()->shutdown();
		}
		$this->getLogger()->info(TextFormat::GREEN."Plugin by TnT_Server");
	}
	
	public function configFile(){
		return $this->getConfig();
	}
	
	public function onDisable(){
		foreach($this->getLoggedIn() as $p){
			$this->logout($this->getServer()->getPlayer($p));
		}
		foreach($this->bans as $banned_players){
		  $this->unban($banned_players);
		}
	}
	
	//HAHA high security~
	private function salt($pw){
		return sha1(md5($this->salt2($pw).$pw.$this->salt2($pw)));
	}
	private function salt2($word){
		return hash('sha256', $word);
	}
	
	private function sendCommandUsage(Player $player, $usage){
	  $player->sendMessage("§r§fUsage: §6".$usage);
	}
	
	public function randomString($length = 10){ 
	  $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'; 
	  $charactersLength = strlen($characters); $randomString = ''; 
	  for ($i = 0; $i < $length; $i++){ 
	    $randomString = $characters[rand(0, $charactersLength - 1)]; 
	  } 
	  return $randomString; 
	}
	
	public function hide(Player $player){
	  foreach($this->getServer()->getOnlinePlayers() as $p){
	    $p->hidePlayer($player);
	  }
	}
	
	public function show(Player $player){
	  foreach($this->getServer()->getOnlinePlayers() as $p){
	    $p->showPlayer($player);
	  }
	}
	
	public function isLoggedIn(Player $player){
		return in_array($player->getName(), $this->login);
	}
	
	public function isRegistered(Player $player){
		$t = $this->data->getAll();
		return isset($t[$player->getName()]["ip"]);
	}
	
	public function auth(Player $player, $method){	
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerAuthEvent($this, $player, $method));
		if($event->isCancelled()){
			return false;
		}
		
		$this->getLogger()->info("玩家 ".$player->getName()." You have auto login to the Game");
		
		$c = $this->configFile()->getAll();
		$t = $this->data->getAll();
		if($c["email"]["remind-players-add-email"] !== false && !isset($t[$player->getName()]["email"])){
			$player->sendMessage("§dYou don't have an email  Please use §6/email <email> to register an email\n§bEmail format:   email@gmail.com");
		}
		
		$this->login[$player->getName()] = $player->getName();
		
		$this->getServer()->broadcastMessage("- §o§b".$player->getName()." §r§eLogin to The Game !");
		
		if($c["vanish-nonloggedin-players"] !== false){
		  foreach($this->getServer()->getOnlinePlayers() as $p){
		    $p->showPlayer($player);
		    $player->sendPopup("§7Removing Vanish....");
		  }
		}
		
		if($event->getMethod() == 0){
			//Do these things for what?
			//Bacause sound can't be played when keyboard is opened
			$player->setHealth($player->getHealth() - 0.1);
			$player->setHealth($player->getHealth() + 1);
			$this->getServer()->getScheduler()->scheduleDelayedTask(new SoundTask($this, $player, 1), 7);
			return false;
		}
		$player->getLevel()->addSound(new BatSound($player), $this->getServer()->getOnlinePlayers());
	}
	
	public function login(Player $player, $password){
		$t = $this->data->getAll();
		$c = $this->configFile()->getAll();
		if(md5($password.$this->salt($password)) != $t[$player->getName()]["password"]){
		  
			$player->sendMessage(TextFormat::RED."Password is wrong!");
			$times = $t[$player->getName()]["times"];
			$left = $c["tries-allowed-to-enter-password"] - $times;
			if($times < $c["tries-allowed-to-enter-password"]){
			  $player->sendMessage("§eYou have§c".$left." §r§echance to type the password!");
			  $t[$player->getName()]["times"] = $times + 1;
			  $this->data->setAll($t);
			  $this->data->save();
			}else{
			  $player->kick("\n§cTry to login too many times\n§e请在 §d".$c["tries-allowed-to-enter-password"]." §eMinutes later come again\n\n\n§ePlease don't use another account");
			  $t[$player->getName()]["times"] = 0;
			  $this->data->setAll($t);
			  $this->data->save();
			  $this->ban($player->getName());
			  $c = $this->configFile()->getAll();
			  $this->getServer()->getScheduler()->scheduleDelayedTask(new UnbanTask($this, $player), $c["time-unban-after-tries-ban-minutes"] * 20 * 60);
			}
			
			return false;
		}
		
		if($t[$player->getName()]["times"] !== 0){
		  $t[$player->getName()]["times"] = 0;
		  $this->data->setAll($t);
		  $this->data->save();
		}
		
		$this->auth($player, 0);
		$player->sendMessage(TextFormat::GREEN."You have login to the game!");
	}
	
	public function logout(Player $player){
		
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerLogoutEvent($this, $player));
		
		if($event->isCancelled()){
			return false;
		}
		
		if(!$this->isLoggedIn($player)){
			$player->sendMessage(TextFormat::YELLOW."你还没有登入");
			return false;
		}
		
		 $player->setHealth($player->getHealth() - 1);
		 $player->setHealth($player->getHealth() + 1);
		 $this->getServer()->getScheduler()->scheduleDelayedTask(new SoundTask($this, $player, 2), 7);
		 
		 $this->getServer()->broadcastMessage("§e§o- §a".$player->getName()."§eLeave the Game");
		 
		 $this->getLogger()->info("Player ".$player->getName()." Leave the game");
		 
		 $c = $this->configFile()->getAll();
		 if($c["vanish-nonloggedin-players"] !== false){
		   foreach($this->getServer()->getOnlinePlayers() as $p){
		     $p->hidePlayer($player);
		     $player->sendPopup("§7§oYou are vanishing...");
		   }
		 }else{
		   
		 }
		
		unset($this->login[$player->getName()]);
	}
	
	public function register(Player $player, $pw1){
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerRegisterEvent($this, $player));
		if($event->isCancelled()){
			$player->sendMessage("§c§oFailed to register");
			return false;
		}
		$t = $this->data->getAll();
		$t[$player->getName()]["password"] = md5($pw1.$this->salt($pw1));
		$t[$player->getName()]["times"] = 0;
		$this->data->setAll($t);
		$this->data->save();
	}
	
	public function isSessionAvailable(Player $player){
		return in_array($player->getName(), $this->session);
	}
	
	public function startSession(Player $player, $minutes=10){
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerAuthSessionStartEvent($this, $player));
		
		if($event->isCancelled()){
			return false;
		}
		
		$this->session[$player->getName()] = $player->getName();
		$this->getServer()->getScheduler()->scheduleDelayedTask(new SessionTask($this, $player), $minutes*1200);
	}
	
	public function closeSession(Player $player){
		$this->getServer()->getPluginManager()->callEvent(new PlayerAuthSessionExpireEvent($this, $player));
		
		unset($this->session[$player->getName()]);
		$player->sendPopup("§7Your name card had expired");
	}
	
	public function ban($name){
	  $this->bans[$name] = $name;
	}
	
	public function unban($name){
	  unset($this->bans[$name]);
	}
	
	public function isBanned($name){
	  return in_array($name, $this->bans);
	}
	
	public function delToken(){
	  $this->token_generated = null;
	}
	
	public function getPlayerEmail($name){
		$t = $this->data->getAll();
	  return $t[$name]["email"];
	}
	
	public function getToken(){
	  return $this->token_generated;
	}
	
	public function onPlayerCommand(PlayerCommandPreprocessEvent $event){
		$t = $this->data->getAll();
		if(substr($event->getMessage(), 0, 5) == "token"){
		  if($event->getMessage() == "token".$this->token_generated){
		    $this->login[$event->getPlayer()->getName()] = $event->getPlayer()->getName();
		    $this->delToken();
		    $event->getPlayer()->sendMessage("§4You use a sign to login");
		    $event->setCancelled(true);
		  }else{
		    $event->getPlayer()->sendMessage("§cSign wrong!");
		    $this->delToken();
		    $this->getLogger()->info("Something gone wrong!");
		    $event->setCancelled(true);
		  }
		}else if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
				$m = $event->getMessage();
				if($m{0} == "/"){
					$event->getPlayer()->sendTip("§cYou cannot use any commnd now!");
					$event->getPlayer()->sendMessage("§b>>>Please type your password<<<");
					$event->setCancelled(true);
				}else{
			  	$this->login($event->getPlayer(), $event->getMessage());
			  }
				$event->setCancelled(true);
			}else{
				if(!isset($t[$event->getPlayer()->getName()]["password"])){
					if(strlen($event->getMessage()) < $this->configFile()->get("min-password-length")){
			     $event->getPlayer()->sendMessage("§cPassword too short!\n§cMust longer than §b".$this->configFile()->get("min-password-length")." §ctext");
			    }else if(strlen($event->getMessage()) > $this->configFile()->get("max-password-length")){
			      $event->getPlayer()->sendMessage("§cPassword too long!\n§cMust shorter than§b".$this->configFile()->get("max-password-length")." §ctext");
			    }else{
     			$this->register($event->getPlayer(), $event->getMessage());
					  $event->getPlayer()->sendMessage(TextFormat::YELLOW.">>>Please type the password again.<<<");
     		}
					$event->setCancelled(true);
				}
				if(!isset($t[$event->getPlayer()->getName()]["confirm"]) && isset($t[$event->getPlayer()->getName()]["password"])){
					$t[$event->getPlayer()->getName()]["confirm"] = $event->getMessage();
					$this->data->setAll($t);
					$this->data->save();
					if(md5($event->getMessage().$this->salt($event->getMessage())) != $t[$event->getPlayer()->getName()]["password"]){
						$event->getPlayer()->sendMessage(TextFormat::YELLOW.">>>Password had ".TextFormat::RED."wrong<<<合".TextFormat::YELLOW."!\n".TextFormat::WHITE."——Please register again——.");
						$event->setCancelled(true);
						unset($t[$event->getPlayer()->getName()]);
						$this->data->setAll($t);
						$this->data->save();
					}else{
