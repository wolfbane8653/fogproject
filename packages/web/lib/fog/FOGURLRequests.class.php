<?php
class FOGURLRequests extends FOGBase
{
	private $handle,$contextOptions;
	public function __construct()
	{
		parent::__construct();
		$ProxyUsed = false;
		if ($this->DB && $this->FOGCore->getSetting('FOG_PROXY_IP'))
		{
			foreach($this->getClass('StorageNodeManager')->find() AS $StorageNode)
				$IPs[] = $this->resolveHostname($StorageNode->get('ip'));
			$IPs = array_filter(array_unique($IPs));
			if (!preg_match('#('.implode('|',$IPs).')#i',$URL))
				$ProxyUsed = true;
			$username = $this->FOGCore->getSetting('FOG_PROXY_USERNAME');
			$password = $this->FOGCore->getSetting('FOG_PROXY_PASSWORD');
		}
		$this->handle = curl_multi_init();
		$this->contextOptions = array(
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPPROXYTUNNEL => $ProxyUsed,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_CONNECTTIMEOUT_MS => 10000,
			$ProxyUsed ? CURLOPT_PROXYAUTH : null => $ProxyUsed ? CURLAUTH_BASIC : null,
			$ProxyUsed ? CURLOPT_PROXYPORT : null => $ProxyUsed ? $this->FOGCore->getSetting('FOG_PROXY_PORT') : null,
			$ProxyUsed ? CUROPT_PROXYTYPE : null => $ProxyUsed ? CURLPROXY_HTTP : null,
			CURLOPT_TIMEOUT_MS => 10000,

			CUROPT_ENCODING => '',
			$ProxyUsed ? CURLOPT_PROXY : null => $ProxyUsed ? $this->FOGCore->getSetting('FOG_PROXY_IP') : null,
			CUROPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.6.12) Gecko/20110319 Firefox/4.0.1 ( .NET CLR 3.5.30729; .NET4.0E)',
			CURLOPT_MAXREDIRS => 20,
			$ProxyUsed && $username ? CURL_PROXYUSERPWD : null => $ProxyUsed && $username ? $username.':'.$password : null,
		);
		$this->array_filter_recursive($this->contextOptions,true);
	}
	public function process($urls, $callback = false)
	{
		foreach ((array)$urls AS $url)
		{
			$ch = curl_init($url);
			curl_setopt_array($ch,$this->contextOptions);
			curl_multi_add_handle($this->handle,$ch);
		}
		do
		{
			$mrc = curl_multi_exec($this->handle,$active);
			if ($state = curl_multi_info_read($this->handle))
			{
				$info = curl_getinfo($state['handle']);
				$data = curl_multi_getcontent($state['handle']);
				if ($callback)
					$callback($data,$info);
				curl_multi_remove_handle($this->handle,$state['handle']);
			}
			usleep(10000); // stop wasting CPU cycles and rest for a couple ms
		} while ($mrc == CURLM_CALL_MULTI_PERFORM || $active);
		return $data;
	}
	public function __destruct()
	{
		curl_multi_close($this->handle);
	}
}