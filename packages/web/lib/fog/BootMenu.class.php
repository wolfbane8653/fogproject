<?php
/**
* \class BootMenu
* Builds the ipxe menu system.
* Serves to also generate the taskings on the fly.
* Changes are automatically adjusted as needed.
* @param $Host is the host set.  Can be null.
* @param $kernel sets the kernel information.
* @param $initrd sets the init information.
* @param $booturl sets the bootup url info.
* @param $memdisk sets the memdisk info
* @param $memtest sets the memtest info
* @param $Host is the host set.  Can be null.
* @param $kernel sets the kernel information.
* @param $initrd sets the init information.
* @param $booturl sets the bootup url info.
* @param $memtest sets the memtest info.
* @param $web sets the web address.
* @param $defaultChoice chooses the defaults.
* @param $bootexittype sets the exit type to hdd.
* @param $storage sets the storage node
* @param $shutdown sets whether shutdown is set or not.
* @param $path sets the default path.
* @param $hiddenmenu sets if hidden menu is setup.
* @param $timeout gets the timout to OS/HDD
* @param $KS gets the key sequence.
* @param $debug sets the debug information. Displays debug menu.
*/
class BootMenu extends FOGBase
{
	// Variables
	private $Host,$kernel,$initrd,$booturl,$memdisk,$memtest,$web,$defaultChoice,$bootexittype,$loglevel;
	private $storage, $shutdown, $path;
	private $hiddenmenu, $timeout, $KS;
	public $debug;
	/** __construct($Host = null)
	* Construtor for the whole system.
	* Sets all the variables as needed.
	* @param $Host can be nothing, but is sent to
	* verify if there's a tasking for the host.
	* @return void
	*/
	public function __construct($Host = null)
	{
		parent::__construct();
		$this->loglevel = 'loglevel='.$this->FOGCore->getSetting('FOG_KERNEL_LOGLEVEL');
		// Setups of the basic construct for the menu system.
		$StorageNode = current($this->getClass('StorageNodeManager')->find(array('isEnabled' => 1, 'isMaster' => 1)));
		// Sets up the default values stored in the server. Lines 51 - 64
		$webserver = $this->FOGCore->resolveHostname($this->FOGCore->getSetting('FOG_WEB_HOST'));
		$webroot = '/'.ltrim(rtrim($this->FOGCore->getSetting('FOG_WEB_ROOT'),'/'),'/').'/';
		$this->web = "${webserver}${webroot}";
		$this->bootexittype = ($this->FOGCore->getSetting('FOG_BOOT_EXIT_TYPE') == 'exit' ? 'exit' : ($this->FOGCore->getSetting('FOG_BOOT_EXIT_TYPE') == 'sanboot' ? 'sanboot --no-describe --drive 0x80' : ($this->FOGCore->getSetting('FOG_BOOT_EXIT_TYPE') == 'grub' ? 'chain -ar http://'.rtrim($this->web,'/').'/service/ipxe/grub.exe --config-file="rootnoverify (hd0);chainloader +1"' : 'exit')));
		$ramsize = $this->FOGCore->getSetting('FOG_KERNEL_RAMDISK_SIZE');
		$dns = $this->FOGCore->getSetting('FOG_PXE_IMAGE_DNSADDRESS');
		$keymap = $this->FOGCore->getSetting('FOG_KEYMAP');
		$memdisk = 'memdisk';
		$memtest = $this->FOGCore->getSetting('FOG_MEMTEST_KERNEL');
		// Default bzImage and imagefile based on arch received.
		$bzImage = ($_REQUEST['arch'] == 'x86_64' ? $this->FOGCore->getSetting('FOG_TFTP_PXE_KERNEL') : $this->FOGCore->getSetting('FOG_TFTP_PXE_KERNEL_32'));
		$kernel = $bzImage;
		$imagefile = ($_REQUEST['arch'] == 'x86_64' ? $this->FOGCore->getSetting('FOG_PXE_BOOT_IMAGE') : $this->FOGCore->getSetting('FOG_PXE_BOOT_IMAGE_32'));
		$initrd = $imagefile;
		// Adjust file info if host is valid.
		if ($Host && $Host->isValid())
		{
			// If the host kernel param is set, use that kernel to boot the host.
			($Host->get('kernel') ? $bzImage = $Host->get('kernel') : null);
			$kernel = $bzImage;
			$this->HookManager->processEvent('BOOT_ITEM_NEW_SETTINGS',array('Host' => &$Host,'StorageGroup' => &$StorageGroup,'StorageNode' => &$StorageNode,'memtest' => &$memtest,'memdisk' => &$memdisk,'bzImage' => &$bzImage,'initrd' => &$initrd,'webroot' => &$webroot,'imagefile' => &$imagefile));
		}
		// Sets the key sequence.  Only used if the hidden menu option is selected.
		$keySequence = $this->FOGCore->getSetting('FOG_KEY_SEQUENCE');
		if ($keySequence)
			$this->KS = new KeySequence($keySequence);
		// menu Access sets if the menu is displayed.  Menu access is a url get variable if a user has specified hidden menu it will override if menuAccess is set.
		if (!$_REQUEST['menuAccess'])
			$this->hiddenmenu = $this->FOGCore->getSetting('FOG_PXE_MENU_HIDDEN');
		if ($this->hiddenmenu)
			$timeout = $this->FOGCore->getSetting('FOG_PXE_HIDDENMENU_TIMEOUT') * 1000;
		else
			$timeout = $this->FOGCore->getSetting('FOG_PXE_MENU_TIMEOUT') * 1000;
		$this->timeout = $timeout;
		// Generate the URL to boot from.
		$this->booturl = "http://${webserver}${webroot}service";
		// Store the host call into class global.
		$this->Host = $Host;
		// Capone menu setup.
		$CaponePlugInst = $_SESSION['capone'];
		$DMISet = $CaponePlugInst ? $this->FOGCore->getSetting('FOG_PLUGIN_CAPONE_DMI') : false;
		// If it is installed store the needed elements into variables.
		if ($CaponePlugInst)
		{
			$this->storage = $this->FOGCore->resolveHostname($StorageNode->get('ip'));
			$this->path = $StorageNode->get('path');
			$this->shutdown = $this->FOGCore->getSetting('FOG_PLUGIN_CAPONE_SHUTDOWN');
		}
		// Create menu item if not exists and Capone is installed as well as the DMI is specified.
		if ($CaponePlugInst && $DMISet)
		{
			// Check for fog.capone if the pxe menu entry exists.
			$PXEMenuItem = current($this->getClass('PXEMenuOptionsManager')->find(array('name' => 'fog.capone')));
			// If it does exist, generate the updated arguments for each call.
			if ($PXEMenuItem && $PXEMenuItem->isValid())
				$PXEMenuItem->set('args',"mode=capone shutdown=$this->shutdown storage=$this->storage:$this->path");
			// If it does not exist, create the menu entry.
			else
			{
				$PXEMenuItem = new PXEMenuOptions(array(
					'name' => 'fog.capone',
					'description' => 'Capone Deploy',
					'args' => "mode=capone shutdown=$this->shutdown storage=$this->storage:$this->path",
					'params' => null,
					'default' => '0',
					'regMenu' => '2',
				));
			}
			$PXEMenuItem->save();
		}
		// Specify the default calls.
		$this->memdisk = "kernel $memdisk";
		$this->memtest = "initrd $memtest";
		$this->kernel = "kernel $bzImage $this->loglevel initrd=$initrd root=/dev/ram0 rw ramdisk_size=$ramsize keymap=$keymap web=${webserver}${webroot} consoleblank=0".($this->FOGCore->getSetting('FOG_KERNEL_DEBUG') ? ' debug' : '');
		$this->initrd = "imgfetch $imagefile";
		// Set the default line based on all the menu entries and only the one with the default set.
		$defMenuItem = current($this->getClass('PXEMenuOptionsManager')->find(array('default' => 1)));
		$this->defaultChoice = "choose --default ".($defMenuItem && $defMenuItem->isValid() ? $defMenuItem->get('name') : 'fog.local').(!$this->hiddenmenu ? " --timeout $timeout" : " --timeout 0").' target && goto ${target}';
		// Register the success of the boot to the database:
		$iPXE = current($this->getClass('iPXEManager')->find(array('product' => $_REQUEST['product'],'manufacturer' => $_REQUEST['manufacturer'],'file' => $_REQUEST['filename'])));
		if ($iPXE && $iPXE->isValid())
		{
			if ($iPXE->get('failure'))
				$iPXE->set('failure',0);
			if (!$iPXE->get('success'))
				$iPXE->set('success',1);
			if (!$iPXE->get('version'))
				$iPXE->set('version',$_REQUEST['ipxever']);
		}
		else if (!$iPXE || !$iPXE->isValid())
		{
			$iPXE = new iPXE(array(
				'product' => $_REQUEST['product'],
				'manufacturer' => $_REQUEST['manufacturer'],
				'mac' => $Host && $Host->isValid() ? $Host->get('mac') : 'no mac',
				'success' => 1,
				'file' => $_REQUEST['filename'],
				'version' => $_REQUEST['ipxever'],
			));
		}
		$iPXE->save();
		if ($_REQUEST['username'] && $_REQUEST['password'])
			$this->verifyCreds();
		else if ($_REQUEST['delconf'])
			$this->delHost();
		else if ($_REQUEST['key'])
			$this->keyset();
		else if ($_REQUEST['sessname'])
			$this->sesscheck();
		else if ($_REQUEST['aprvconf'])
			$this->approveHost();
		else if (!$Host || !$Host->isValid())
			$this->printDefault();
		else
			$this->getTasking();
	}
	/**
	* chainBoot()
	* Prints the bootmenu or hides it.  If access is not allowed but tried
	* requests login information from WEB GUI.
	* Used often for return to menu/check tasking after setting somthing.
	* $debug is a flat to indicate if we show the debug menu item.  Typically
	* you only want this after a person authenticates.
	* $shortCircuit is a flag that will shortCircuit the hiddenMenu check.
	* This is needed for quick image.
	* @param $debug set to false but if true enables access.
	* @param $shortCircuit set to false, but if true enables display.
	* @return void
	*/
	private function chainBoot($debug=false, $shortCircuit=false)
	{
	    // csyperski: added hiddenMenu check; without it entering
		// any string for username and password would show the menu, even if it was hidden
	    if (!$this->hiddenmenu || $shortCircuit)
		{
    		$Send['chainnohide'] = array(
				"#!ipxe",
				"cpuid --ext 29 && set arch x86_64 || set arch i386",
				"params",
				'param mac0 ${net0/mac}',
				'param arch ${arch}',
				"param menuAccess 1",
				"param debug ".($debug ? 1 : 0),
				'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
				'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
				":bootme",
	    		"chain -ar $this->booturl/ipxe/boot.php##params",
			);
	    } 
	    else
	    {
	        $Send['chainhide'] = array(
				"#!ipxe",
				"prompt --key ".($this->KS && $this->KS->isValid() ? $this->KS->get('ascii') : '0x1b')." --timeout $this->timeout Booting... (Press ".($this->KS && $this->KS->isValid() ?  $this->KS->get('name') : 'Escape')." to access the menu) && goto menuAccess || $this->bootexittype",
				":menuAccess",
				"login",
				"params",
				'param mac0 ${net0/mac}',
				'param arch ${arch}',
				'param username ${username}',
				'param password ${password}',
				"param menuaccess 1",
				"param debug ".($debug ? 1 : 0),
				'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
				'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
				":bootme",
				"chain -ar $this->booturl/ipxe/boot.php##params",
			);
	    }
		$this->parseMe($Send);
	}
	/**
	* delHost()
	* Deletes the host from the system.
	* If it fails will return that it failed.
	* Each interval sends back to chainBoot()
	* @return void
	*/
	private function delHost()
	{
		if($this->Host->destroy())
		{
			$Send['delsuccess'] = array(
				"#!ipxe",
				"echo Host deleted successfully",
				"sleep 3"
			);
		}
		else
		{
			$Send['delfail'] = array(
				"#!ipxe",
				"echo Failed to destroy Host!",
				"sleep 3",
			);
		}
		$this->parseMe($Send);
		$this->chainBoot();
	}
	private function printImageIgnored()
	{
		$Send['ignored'] = array(
			"#!ipxe",
			"echo The MAC Address is set to be ignored for imaging tasks",
			"sleep 15",
		);
		$this->parseMe($Send);
		$this->noMenu();
	}
	private function approveHost()
	{
		if ($this->Host->set('pending',null)->save())
		{
			$Send['approvesuccess'] = array(
				"#!ipxe",
				"echo Host approved successfully",
				"sleep 3"
			);
			$this->Host->createImagePackage(10,'Inventory',false,false,false,false,$_REQUEST['username']);
		}
		else
		{
			$Send['approvefail'] = array(
				"#!ipxe",
				"echo Host approval failed",
				"sleep 3"
			);
		}
		$this->parseMe($Send);
		$this->chainBoot();
	}
	/**
	* printTasking()
	* Sends the Tasking file.  In PXE this is equivalent to the creation
	* of the 01-XX-XX-XX-XX-XX-XX file.
	* Just tells the system it's got a task.
	* @param $kernelArgsArray sets up the tasking through the 
	* kernelArgs information.
	* @return void
	*/
	private function printTasking($kernelArgsArray)
	{
		foreach($kernelArgsArray AS $arg)
        {   
            if (!is_array($arg) && !empty($arg) || (is_array($arg) && $arg['active'] && !empty($arg)))
                $kernelArgs[] = (is_array($arg) ? $arg['value'] : $arg);
        }   
        $kernelArgs = array_unique($kernelArgs);
		$Send['task'] = array(
			"#!ipxe",
        	"$this->kernel ".implode(' ',(array)$kernelArgs),
        	"$this->initrd",
        	"boot",
		);
		$this->parseMe($Send);
	}
	/**
	* delConf()
	* If you're trying to delete the host, requests confirmation of deletion.
	* @return void
	*/
	public function delConf()
	{
		$Send['delconfirm'] = array(
			"#!ipxe",
			"cpuid --ext 29 && set arch x86_64 || set arch i386",
			"prompt --key y Would you like to delete this host? (y/N): &&",
			"params",
			'param mac0 ${net0/mac}',
			'param arch ${arch}',
			"param delconf 1",
			'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
			'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
			":bootme",
			"chain -ar $this->booturl/ipxe/boot.php##params",
		);
		$this->parseMe($Send);
	}
	/**
	* aprvConf()
	* If you're trying to approve the host, request confirmation.
	* @return void
	*/
	public function aprvConf()
	{
		$Send['aprvconfirm'] = array(
			"#!ipxe",
			"cpuid --ext 29 && set arch x86_64 || set arch i386",
			"prompt --key y Would you like to approve this host? (y/N): &&",
			"params",
			'param mac0 ${net0/mac}',
			'param arch ${arch}',
			"param aprvconf 1",
			'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
			'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
			":bootme",
			"chain -ar $this->booturl/ipxe/boot.php##params",
		);
		$this->parseMe($Send);
	}
	/**
	* keyreg()
	* If you're trying to change the key, request what the key is.
	* @return void
	*/
	public function keyreg()
	{
		$Send['keyreg'] = array(
			"#!ipxe",
			"cpuid --ext 29 && set arch x86_64 || set arch i386",
			"echo -n Please enter the product key>",
			"read key",
			"params",
			'param mac0 ${net0/mac}',
			'param arch ${arch}',
			'param key ${key}',
			'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
			'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
			":bootme",
			"chain -ar $this->booturl/ipxe/boot.php##params",
		);
		$this->parseMe($Send);
	}
	/**
	* sesscheck()
	* Verifys the name
	* @return void
	*/
	public function sesscheck()
	{
		$sesscount = current($this->getClass('MulticastSessionsManager')->find(array('name' => $_REQUEST['sessname'],'stateID' => array(0,1,2,3))));
		if (!$sesscount || !$sesscount->isValid())
		{
			$Send['checksession'] = array(
				"#!ipxe",
				"echo No session found with that name.",
				"clear sessname",
				"sleep 3",
				"cpuid --ext 29 && set arch x86_64 || set arch i386",
				"params",
				'param mac0 ${net0/mac}',
				'param arch ${arch}',
				"param sessionJoin 1",
				'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
				'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
				":bootme",
				"chain -ar $this->booturl/ipxe/boot.php##params",
			);
			$this->parseMe($Send);
		}
		else
			$this->multijoin($sesscount->get('id'));
	}

	/**
	* sessjoin()
	* Gets the relevant information and passes when verified.
	* @return void
	*/
	public function sessjoin()
	{
		$Send['joinsession'] = array(
			"#!ipxe",
			"cpuid --ext 29 && set arch x86_64 || set arch i386",
			"echo -n Please enter the session name to join>",
			"read sessname",
			"params",
			'param mac0 ${net0/mac}',
			'param arch ${arch}',
			'param sessname ${sessname}',
			'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
			'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
			":bootme",
			"chain -ar $this->booturl/ipxe/boot.php##params",
		);
		$this->parseMe($Send);
	}
	/**falseTasking() only runs if hosts aren't registered
	* @param $mc = false, only specified if the task is multicast.
	* @param $Image = send the specified image, really only needed for non-multicast
	* @return void
	**/
	public function falseTasking($mc = false,$Image = false)
	{
		$TaskType = new TaskType(1);
		if ($mc)
		{
			$Image = $mc->getImage();
			$TaskType = new TaskType(8);
		}
		$StorageGroup = $Image->getStorageGroup();
		$StorageNode = $StorageGroup->getOptimalStorageNode();
		$osid = $Image->get('osID');
		$storage = sprintf('%s:/%s/%s',$this->FOGCore->resolveHostname(trim($StorageNode->get('ip'))),trim($StorageNode->get('path'),'/'),'');
		$storageip = $this->FOGCore->resolveHostname($StorageNode->get('ip'));
		$img = $Image->get('path');
		$imgFormat = $Image->get('format');
		$imgType = $Image->getImageType()->get('type');
		$imgPartitionType = $Image->getImagePartitionType()->get('type');
		$imgid = $Image->get('id');
		$chkdsk = $this->FOGCore->getSetting('FOG_DISABLE_CHKDSK') == 1 ? 0 : 1;
		$ftp = $this->FOGCore->resolveHostname($this->FOGCore->getSetting('FOG_TFTP_HOST'));
		$port = ($mc ? $mc->get('port') : null);
		$miningcores = $this->FOGCore->getSetting('FOG_MINING_MAX_CORES');
		$kernelArgsArray = array(
			"mac=$mac",
			"ftp=$ftp",
			"storage=$storage",
			"storageip=$storageip",
			"web=$this->web",
			"osid=$osid",
			"consoleblank=0",
			"irqpoll",
			"chkdsk=$chkdsk",
			"img=$img",
			"imgType=$imgType",
			"imgPartitionType=$imgPartitionType",
			"imgid=$imgid",
			"imgFormat=$imgFormat",
			"shutdown=0",
			array(
				'value' => "capone=1",
				'active' => !$this->Host || !$this->Host->isValid(),
			),
			array(
				'value' => "port=$port mc=yes",
				'active' => $mc,
			),
			array(
				'value' => "mining=1 miningcores=$miningcores",
				'active' => $this->FOGCore->getSetting('FOG_MINING_ENABLE'),
			),
			array(
				'value' => 'debug',
				'active' => $this->FOGCore->getSetting('FOG_KERNEL_DEBUG'),
			),
			$TaskType->get('kernelArgs'),
			$this->FOGCore->getSetting('FOG_KERNEL_ARGS'),
		);
		$this->printTasking($kernelArgsArray);
	}
	public function printImageList()
	{
		$Send['ImageListing'] = array(
			'#!ipxe',
			'goto MENU',
			':MENU',
			'menu',
		);
		$defItem = 'choose target && goto ${target}';
		$Images = $this->getClass('ImageManager')->find();
		if (!$Images)
		{
			$Send['NoImages'] = array(
				'#!ipxe',
				'echo No Images on server found',
				'sleep 3',
			);
			$this->parseMe($Send);
			$this->chainBoot();
		}
		else
		{
			foreach($Images AS $Image)
			{
				// Only create menu items if the image is valid.
				if ($Image && $Image->isValid())
				{
					array_push($Send['ImageListing'],"item ".$Image->get('path').' '.$Image->get('name'));
					// If the host is valid and the image is set and valid, set the selected target.
					if ($this->Host && $this->Host->isValid() && $this->Host->getImage() && $this->Host->getImage()->isValid() && $this->Host->getImage()->get('id') == $Image->get('id'))
						$defItem = 'choose --default '.$Image->get('path').' target && goto ${target}';
				}
			}
			// Add the return to other menu
			array_push($Send['ImageListing'],'item return Return to menu');
			// Insert the choice of menu item.
			array_push($Send['ImageListing'],$defItem);
			foreach($Images AS $Image)
			{
				if ($Image && $Image->isValid())
				{
					$Send['pathofimage'.$Image->get('name')] = array(
						':'.$Image->get('path'),
						'set imageID '.$Image->get('id'),
						'params',
						'param mac0 ${net0/mac}',
						'param arch ${arch}',
						'param imageID ${imageID}',
						'param qihost 1',
						'param username ${username}',
						'param password ${password}',
						'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
						'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
					);
				}
			}
			$Send['returnmenu'] = array(
				':return',
				'params',
				'param mac0 ${net0/mac}',
				'param arch ${arch}',
				'isset ${net1/mac} && param mac1 ${net1/mac} || goto bootme',
				'isset ${net2/mac} && param mac2 ${net2/mac} || goto bootme',
			);
			$Send['bootmefunc'] = array(
				':bootme',
				'chain -ar '.$this->booturl.'/ipxe/boot.php##params',
				'goto MENU',
			);
			$this->parseMe($Send);
		}
	}
	/**
	* multijoin()
	* Joins the host to an already generated multicast session
	* @return void
	*/
	public function multijoin($msid)
	{
		$MultiSess = new MulticastSessions($msid);
		if ($MultiSess && $MultiSess->isValid())
		{
			if ($this->Host && $this->Host->isValid())
			{
				$this->Host->set('image',$MultiSess->get('image'));
				 // Create the host task
				if($this->Host->createImagePackage(8,$MultiSess->get('name'),false,false,true,false,$_REQUEST['username'],'',true))
					$this->chainBoot(false,true);
			}
			else
				$this->falseTasking($MultiSess);
		}
	}
	/**
	* keyset()
	* Set's the product key using the ipxe menu.
	* @return void
	*/
	public function keyset()
	{
		$this->Host->set('productKey',base64_encode($_REQUEST['key']));
		if ($this->Host->save())
		{
			$Send['keychangesuccess'] = array(
				"#!ipxe",
				"echo Successfully changed key",
				"sleep 3",
			);
			$this->parseMe($Send);
			$this->chainBoot();
		}
	}
	/**
	* parseMe($Send)
	* @param $Send the data to be sent.
	* @return void
	*/
	private function parseMe($Send)
	{
		$this->HookManager->processEvent('IPXE_EDIT',array('ipxe' => &$Send,'Host' => &$this->Host,'kernel' => &$this->kernel,'initrd' => &$this->initrd,'booturl' => &$this->booturl, 'memdisk' => &$this->memdisk,'memtest' => &$this->memtest, 'web' => &$this->web, 'defaultChoice' => &$this->defaultChoice, 'bootexittype' => &$this->bootexittype,'storage' => &$this->storage,'shutdown' => &$this->shutdown,'path' => &$this->path,'timeout' => &$this->timeout,'KS' => $this->ks));
		foreach($Send AS $ipxe => $val)
			print implode("\n",$val)."\n";
	}
	/**
	* advLogin()
	* If advanced login is set this just passes when verifyCreds is correct
	* @return void
	*/
	public function advLogin()
	{
		$Send['advancedlogin'] = array(
			"#!ipxe",
			"chain -ar $this->booturl/ipxe/advanced.php",
		);
		$this->parseMe($Send);
	}
	/**
	* debugAccess()
	* Set's up for debug menu as requested.
	* @return void
	*/
	private function debugAccess()
	{
		$Send['debugaccess'] = array(
			"#!ipxe",
			"$this->kernel mode=onlydebug",
			"$this->initrd",
			"boot",
		);
		$this->parseMe($Send);
	}
	/**
	* verifyCreds()
	* Verifies the login information is valid
	* and correct.
	* Otherwise return that it's broken.
	* @return void
	*/
	public function verifyCreds()
	{
		if ($this->FOGCore->attemptLogin($_REQUEST['username'],$_REQUEST['password']))
		{
			if ($this->FOGCore->getSetting('FOG_ADVANCED_MENU_LOGIN') && $_REQUEST['advLog'])
				$this->advLogin();
			if ($_REQUEST['delhost'])
				$this->delConf();
			else if ($_REQUEST['keyreg'])
				$this->keyreg();
			else if ($_REQUEST['qihost'] && !$_REQUEST['imageID'])
				$this->setTasking();
			else if ($_REQUEST['qihost'] && $_REQUEST['imageID'])
				$this->setTasking($_REQUEST['imageID']);
			else if ($_REQUEST['sessionJoin'])
				$this->sessjoin();
			else if ($_REQUEST['approveHost'])
				$this->aprvConf();
			else if ($_REQUEST['menuaccess'])
			{
				unset($this->hiddenmenu);
				$this->chainBoot(true);
			}
			else if ($_REQUEST['debugAccess'])
				$this->debugAccess();
			else if (!$this->FOGCore->getSetting('FOG_NO_MENU'))
				$this->printDefault();
			else
				$this->noMenu();
		}
		else
		{
			$Send['invalidlogin'] = array(
				"#!ipxe",
				"echo Invalid login!",
				"clear username",
				"clear password",
				"sleep 3",
			);
			$this->parseMe($Send);
			$this->chainBoot();
		}
	}
	/**
	* setTasking()
	* If quick image tasking requested, this sets up the tasking.
	* @return void
	*/
	public function setTasking($imgID = '')
	{
		if (!$imgID)
			$this->printImageList();
		if ($imgID)
		{
			$Image = new Image($imgID);
			if ($this->Host && $this->Host->isValid())
			{
				if (!$this->Host->getImage() || !$this->Host->getImage()->isValid())
					$this->Host->set('image',$imgID);
				if ($imgID && $this->Host->getImage() && $this->Host->getImage()->isValid() && $imgID != $this->Host->getImage()->get('id'))
					$this->Host->set('image',$imgID);
				if ($this->Host->getImage()->isValid())
				{
					try
					{
						if($this->Host->createImagePackage(1,'AutoRegTask',false,false,true,false,$_REQUEST['username']))
							$this->chainBoot(false, true);
					}
					catch (Exception $e)
					{
						$Send['fail'] = array(
							'#!ipxe',
							'echo '.$e->getMessage(),
							'sleep 3',
						);
						$this->parseMe($Send);
					}
				}
			}
			else
				$this->falseTasking('',$Image);
			$this->chainBoot(false,true);
		}
	}
	/**
	* noMenu()
	* If no menu option is set, just exits to harddrive if there's no tasking.
	* @return void
	*/
	public function noMenu()
	{
		$Send['nomenu'] = array(
			"#!ipxe",
			"$this->bootexittype",
		);
		$this->parseMe($Send);
	}
	/**
	* getTasking()
	* Finds out if there's a tasking for the relevant host.
	* if there is, returns the printTasking, otherwise 
	* presents the menu.
	* @return void
	*/
	public function getTasking()
	{
		$Task = $this->Host->get('task');
		if (!$Task->isValid())
		{
			if ($this->FOGCore->getSetting('FOG_NO_MENU'))
				$this->noMenu();
			else
				$this->printDefault();
		}
		else
		{
			if ($this->Host->get('mac')->isImageIgnored())
				$this->printImageIgnored();
			$TaskType = new TaskType($Task->get('typeID'));
			$imagingTasks = array(1,2,8,15,16,17,24);
			if ($TaskType->isMulticast())
			{
				$MulticastSessionAssoc = current($this->getClass('MulticastSessionsAssociationManager')->find(array('taskID' => $Task->get('id'))));
				$MulticastSession = new MulticastSessions($MulticastSessionAssoc->get('msID'));
				if ($MulticastSession && $MulticastSession->isValid())
					$this->Host->set('image',$MulticastSession->get('image'));
			}
			if (in_array($TaskType->get('id'),$imagingTasks))
			{
				$Image = $Task->getImage();
				$StorageGroup = $Image->getStorageGroup();
				$StorageNode = $StorageGroup->getOptimalStorageNode();
				$this->HookManager->processEvent('BOOT_TASK_NEW_SETTINGS',array('Host' => &$this->Host,'StorageNode' => &$StorageNode,'StorageGroup' => &$StorageGroup));
				if ($TaskType->isUpload() || $TaskType->isMulticast())
					$StorageNode = $StorageGroup->getMasterStorageNode();
				$osid = $Image->get('osID');
				$storage = in_array($TaskType->get('id'),$imagingTasks) ? sprintf('%s:/%s/%s',$this->FOGCore->resolveHostname(trim($StorageNode->get('ip'))),trim($StorageNode->get('path'),'/'),($TaskType->isUpload() ? 'dev/' : '')) : null;
			}
			if ($this->Host && $this->Host->isValid())
				$mac = $this->Host->get('mac');
			else
				$mac = $_REQUEST['mac'];
			$clamav = in_array($TaskType->get('id'),array(21,22)) ? sprintf('%s:%s',$this->FOGCore->resolveHostname(trim($StorageNode->get('ip'))),'/opt/fog/clamav') : null;
			$storageip = in_array($TaskType->get('id'),$imagingTasks) ? $this->FOGCore->resolveHostname($StorageNode->get('ip')) : null;
			$img = in_array($TaskType->get('id'),$imagingTasks) ? $Image->get('path') : null;
			$imgFormat = in_array($TaskType->get('id'),$imagingTasks) ? $Image->get('format') : null;
			$imgType = in_array($TaskType->get('id'),$imagingTasks) ? $Image->getImageType()->get('type') : null;
			$imgPartitionType = in_array($TaskType->get('id'),$imagingTasks) ? $Image->getImagePartitionType()->get('type') : null;
			$imgid = in_array($TaskType->get('id'),$imagingTasks) ? $Image->get('id') : null;
			$ftp = $this->FOGCore->resolveHostname($this->FOGCore->getSetting('FOG_TFTP_HOST'));
			$chkdsk = $this->FOGCore->getSetting('FOG_DISABLE_CHKDSK') == 1 ? 0 : 1;
			$PIGZ_COMP = in_array($TaskType->get('id'),$imagingTasks) ? ($Image->get('compress') > -1 && is_numeric($Image->get('compress')) ? $Image->get('compress') : $this->FOGCore->getSetting('FOG_PIGZ_COMP')) : $this->FOGCore->getSetting('FOG_PIGZ_COMP');
			$kernelArgsArray = array(
				"mac=$mac",
				"ftp=$ftp",
				"storage=$storage",
				"storageip=$storageip",
				"web=$this->web",
				"osid=$osid",
				"consoleblank=0",
				"irqpoll",
				"hostname=".$this->Host->get('name'),
				array(
					'value' => "clamav=$clamav",
					'active' => in_array($TaskType->get('id'),array(21,22)),
				),
				array(
					'value' => "chkdsk=$chkdsk",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "img=$img",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "imgType=$imgType",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "imgPartitionType=$imgPartitionType",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "imgid=$imgid",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "imgFormat=$imgFormat",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => "PIGZ_COMP=-$PIGZ_COMP",
					'active' => in_array($TaskType->get('id'),$imagingTasks),
				),
				array(
					'value' => 'shutdown=1',
					'active' => $Task->get('shutdown'),
				),
				array(
					'value' => 'adon=1',
					'active' => $this->Host->get('useAD'),
				),
				array(
					'value' => 'addomain='.$this->Host->get('ADDomain'),
					'active' => $this->Host->get('useAD'),
				),
				array(
					'value' => 'adou='.$this->Host->get('ADOU'),
					'active' => $this->Host->get('useAD'),
				),
				array(
					'value' => 'aduser='.$this->Host->get('ADUser'),
					'active' => $this->Host->get('useAD'),
				),
				array(
					'value' => 'adpass='.$this->Host->get('ADPass'),
					'active' => $this->Host->get('useAD'),
				),
				array(
					'value' => 'fdrive='.$this->Host->get('kernelDevice'),
					'active' => $this->Host->get('kernelDevice'),
				),
				array(
					'value' => 'hostearly=1',
					'active' => $this->FOGCore->getSetting('FOG_CHANGE_HOSTNAME_EARLY') && in_array($TaskType->get('id'),$imagingTasks) ? true : false,
				),
				array(
					'value' => 'pct='.(is_numeric($this->FOGCore->getSetting('FOG_UPLOADRESIZEPCT')) && $this->FOGCore->getSetting('FOG_UPLOADRESIZEPCT') >= 5 && $this->FOGCore->getSetting('FOG_UPLOADRESIZEPCT') < 100 ? $this->FOGCore->getSetting('FOG_UPLOADRESIZEPCT') : '5'),
					'active' => $TaskType->isUpload() && in_array($TaskType->get('id'),$imagingTasks) ? true : false,
				),
				array(
					'value' => 'ignorepg='.($this->FOGCore->getSetting('FOG_UPLOADIGNOREPAGEHIBER') ? 1 : 0),
					'active' => $TaskType->isUpload() && in_array($TaskType->get('id'),$imagingTasks) ? true : false,
				),
				array(
					'value' => 'port='.($TaskType->isMulticast() ? $MulticastSession->get('port') : null),
					'active' => $TaskType->isMulticast(),
				),
				array(
					'value' => 'mining=1',
					'active' => $this->FOGCore->getSetting('FOG_MINING_ENABLE'),
				),
				array(
					'value' => 'miningcores=' . $this->FOGCore->getSetting('FOG_MINING_MAX_CORES'),
					'active' => $this->FOGCore->getSetting('FOG_MINING_ENABLE'),
				),
				array(
					'value' => 'winuser='.$Task->get('passreset'),
					'active' => $TaskType->get('id') == '11' ? true : false,
				),
				array(
					'value' => 'miningpath=' . $this->FOGCore->getSetting('FOG_MINING_PACKAGE_PATH'),
					'active' => $this->FOGCore->getSetting('FOG_MINING_ENABLE'),
				),
				array(
					'value' => 'isdebug=yes',
					'active' => $Task->get('isDebug'),
				),
				array(
					'value' => 'debug',
					'active' => $this->FOGCore->getSetting('FOG_KERNEL_DEBUG'),
				),
				$TaskType->get('kernelArgs'),
				$this->FOGCore->getSetting('FOG_KERNEL_ARGS'),
				$this->Host->get('kernelArgs'),
			);
			if ($Task->get('typeID') == 12 || $Task->get('typeID') == 13)
				$this->printDefault();
			else if ($Task->get('typeID') == 4)
			{
				$Send['memtest'] = array(
					"#!ipxe",
					"$this->memdisk iso raw",
					"$this->memtest",
					"boot",
				);
				$this->parseMe($Send);
			}
			else
				$this->printTasking($kernelArgsArray);
		}
	}
	/**
	* menuItem()
	* @param $option the menu option
	* @param $desc the description of the menu item.
	* Prints the menu items.
	* @return the string as passed.
	*/
	private function menuItem($option, $desc)
	{
		return array("item ".$option->get('name')." ".$option->get('description'));
	}
	/**
	* menuOpt()
	* Prints the actual menu related items for booting.
	* @param $option the related menu option
	* @param $type the type of menu information
	* @return $Send sends the data for the menu item.
	*/
	private function menuOpt($option,$type)
	{
		if ($option->get('id') == 1)
		{
			$Send = array(
				":".$option->get('name'),
				"$this->bootexittype || goto MENU",
			);
		}
		else if ($option->get('id') == 2)
		{
			$Send = array(
				":".$option->get('name'),
				"$this->memdisk iso raw",
				"$this->memtest",
				"boot || goto MENU",
			);
		}
		else if ($option->get('id') == 11)
		{
			$Send = array(
				":".$option->get('name'),
				"chain -ar $this->booturl/ipxe/advanced.php || goto MENU",
			);
		}
		else if ($option->get('params'))
		{
			$Send = array(
				':'.$option->get('name'),
				$option->get('params'),
			);
		}
		else
		{
			$Send = array(
				":$option",
				"$this->kernel $this->loglevel $type",
				"$this->initrd",
				"boot || goto MENU",
			);
		}
		return $Send;
	}
	/**
	* printDefault()
	* Prints the Menu which is equivalent to the
	* old default file from PXE boot.
	* @return void
	*/
	public function printDefault()
	{
		// Gets all the database menu items.
		$Menus = $this->getClass('PXEMenuOptionsManager')->find('','','id');
		$Send['head'] = array(
			"#!ipxe",
			'set fog-ip '.$this->FOGCore->getSetting('FOG_WEB_HOST'),
			'set fog-webroot '.basename($this->FOGCore->getSetting('FOG_WEB_ROOT')),
			'set boot-url http://${fog-ip}/${fog-webroot}',
			"cpuid --ext 29 && set arch x86_64 || set arch i386",
			"goto get_console",
			":console_set",
			"colour --rgb 0xff6600 2",
			"cpair --foreground 7 --background 2 2",
			"goto MENU",
			":alt_console",
			"cpair --background 0 1 && cpair --background 1 2",
			"goto MENU",
			":get_console",
			"console --picture $this->booturl/ipxe/bg.png --left 100 --right 80 && goto console_set || goto alt_console",
		);
		if (!$this->hiddenmenu)
		{
		    $showDebug = $_REQUEST["debug"] === "1";
			$Send['menustart'] = array(
				":MENU",
				"menu",
				"colour --rgb ".($this->Host && $this->Host->isValid() ? "0x00ff00" : "0xff0000")." 0",
				"cpair --foreground 0 3",
				"item --gap Host is ".($this->Host && $this->Host->isValid() ? ($this->Host->get('pending') ? 'pending ' : '')."registered as ".$this->Host->get('name') : "NOT registered!"),
				"item --gap -- -------------------------------------",
			);
			$Advanced = $this->FOGCore->getSetting('FOG_PXE_ADVANCED');
			$AdvLogin = $this->FOGCore->getSetting('FOG_ADVANCED_MENU_LOGIN');
			$ArrayOfStuff = array(($this->Host && $this->Host->isValid() ? ($this->Host->get('pending') ? 6 : 1) : 0),2);
			if ($showDebug)
				array_push($ArrayOfStuff,3);
			if ($Advanced)
				array_push($ArrayOfStuff,($AdvLogin ? 5 : 4));
			foreach($Menus AS $Menu)
			{
				if (!in_array($Menu->get('name'),array('fog.reg','fog.reginput')) || (in_array($Menu->get('name'),array('fog.reg','fog.reginput')) && $this->FOGCore->getSetting('FOG_REGISTRATION_ENABLED')))
				{
					if (in_array($Menu->get('regMenu'),$ArrayOfStuff))
						$Send['item-'.$Menu->get('name')] = $this->menuItem($Menu, $desc);
				}
			}
			$Send['default'] = array(
				"$this->defaultChoice",
			);
			foreach($Menus AS $Menu)
			{
				if (in_array($Menu->get('regMenu'),$ArrayOfStuff))
					$Send['choice-'.$Menu->get('name')] = $Menu->get('args') ? $this->menuOpt($Menu,$Menu->get('args')) : $this->menuOpt($Menu,true);
			}
			$Send['bootme'] = array(
				":bootme",
				"chain -ar $this->booturl/ipxe/boot.php##params ||",
				"goto MENU",
				"autoboot",
			);
			$this->parseMe($Send);
		}
		else
			$this->chainBoot(true);
	}
}
/* Local Variables: */
/* indent-tabs-mode: t */
/* c-basic-offset: 4 */
/* tab-width: 4 */
/* End: */
