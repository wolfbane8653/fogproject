<?php
class LDAPPluginHook extends Hook
{
	var $name = 'LDAPPluginHook';
	var $description = 'LDAP Hook';
	var $author = 'Fernando Gietz';
	var $active = true;
	var $node = 'ldap';
	public function check_addUser($arguments)
	{
		$username = $arguments['username'];
		$password = $arguments['password'];
		$User = $arguments['User'];
		if (in_array($this->node,$_SESSION['PluginsInstalled']))
		{
			foreach($this->getClass('LDAPManager')->find() AS $LDAP)
			{
				if ($LDAP->authLDAP($username,$password))
				{
					$UserByName = current($this->getClass('UserManager')->find(array('name' => $username)));
					if ($UserByName)
					{
						$arguments['User'] = $UserByName;
						break;
					}
					else if (!$User || !$User->isValid())
					{
						$tmpUser = new User(array(
								'name' => $username,
								'type' => 1,
								'password' => md5($password),
								'createdBy' => 'fog',
						));
						if ($tmpUser->save())
						{
							$this->FOGCore->logHistory(sprintf('%s: ID: %s, Name: %s', _('User created'), $tmpUser->get('id'), $tmpUser->get('name')));
							$arguments['User'] = $tmpUser;
							break;
						}
						else
							throw new Exception('Database update failed');
					}
				}
			}
		}
	}

}
$LDAPPluginHook = new LDAPPluginHook();
// Register Hooks
$HookManager->register('USER_LOGGING_IN', array($LDAPPluginHook,'check_addUser'));
