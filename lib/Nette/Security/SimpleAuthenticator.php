<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Security
 */



/**
 * Trivial implementation of IAuthenticator.
 *
 * @author     David Grudl
 * @package Nette\Security
 */
class NSimpleAuthenticator extends NObject implements IAuthenticator
{
	/** @var array */
	private $userlist;


	/**
	 * @param  array  list of pairs username => password
	 */
	public function __construct(array $userlist)
	{
		$this->userlist = $userlist;
	}



	/**
	 * Performs an authentication against e.g. database.
	 * and returns IIdentity on success or throws NAuthenticationException
	 * @return IIdentity
	 * @throws NAuthenticationException
	 */
	public function authenticate(array $credentials)
	{
		list($username, $password) = $credentials;
		foreach ($this->userlist as $name => $pass) {
			if (strcasecmp($name, $username) === 0) {
				if ((string) $pass === (string) $password) {
					return new NIdentity($name);
				} else {
					throw new NAuthenticationException("Invalid password.", self::INVALID_CREDENTIAL);
				}
			}
		}
		throw new NAuthenticationException("User '$username' not found.", self::IDENTITY_NOT_FOUND);
	}

}
