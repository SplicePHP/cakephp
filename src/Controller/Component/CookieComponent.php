<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Cake\Utility\Time;

/**
 * Cookie Component.
 *
 * Provides enhanced cookie handling features for use in the controller layer.
 * In addition to the basic features offered be Cake\Network\Response, this class lets you:
 *
 * - Create and read encrypted cookies.
 * - Store non-scalar data.
 * - Use hash compatible syntax to read/write/delete values.
 *
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html
 */
class CookieComponent extends Component {

/**
 * Default config
 *
 * - `expires` - How long the cookies should last for. Defaults to 1 month.
 * - `path` - The path on the server in which the cookie will be available on.
 *   If path is set to '/foo/', the cookie will only be available within the
 *   /foo/ directory and all sub-directories such as /foo/bar/ of domain.
 *   The default value is the entire domain.
 * - `domain` - The domain that the cookie is available. To make the cookie
 *   available on all subdomains of example.com set domain to '.example.com'.
 * - `secure` - Indicates that the cookie should only be transmitted over a
 *   secure HTTPS connection. When set to true, the cookie will only be set if
 *   a secure connection exists.
 * - `key` - Encryption key used when encrypted cookies are enabled. Defaults to Security.salt.
 * - `httpOnly` - Set to true to make HTTP only cookies. Cookies that are HTTP only
 *   are not accessible in JavaScript. Default false.
 * - `encryption` - Type of encryption to use. Defaults to 'aes'.
 *
 * @var array
 */
	protected $_defaultConfig = [
		'path' => '/',
		'domain' => '',
		'secure' => false,
		'key' => null,
		'httpOnly' => false,
		'encryption' => 'aes',
		'expires' => '+1 month',
	];

/**
 * Config specific to a given top level key name.
 *
 * The values in this array are merged with the general config
 * to generate the configuration for a given top level cookie name.
 *
 * @var array
 */
	protected $_keyConfig = [];

/**
 * Values stored in the cookie.
 *
 * Accessed in the controller using $this->Cookie->read('Name.key');
 *
 * @var string
 */
	protected $_values = [];

/**
 * A map of keys that have been loaded.
 *
 * Since CookieComponent lazily reads cookie data,
 * we need to track which cookies have been read to account for
 * read, delete, read patterns.
 *
 * @var array
 */
	protected $_loaded = [];

/**
 * A reference to the Controller's Cake\Network\Response object
 *
 * @var \Cake\Network\Response
 */
	protected $_response = null;

/**
 * The request from the controller.
 *
 * @var \Cake\Network\Request
 */
	protected $_request;

/**
 * Valid cipher names for encrypted cookies.
 *
 * @var array
 */
	protected $_validCiphers = ['aes', 'rijndael'];

/**
 * Constructor
 *
 * @param ComponentRegistry $collection A ComponentRegistry for this component
 * @param array $config Array of config.
 */
	public function __construct(ComponentRegistry $collection, array $config = array()) {
		parent::__construct($collection, $config);

		if (!$this->_config['key']) {
			$this->config('key', Configure::read('Security.salt'));
		}

		$controller = $collection->getController();
		if ($controller && isset($controller->request)) {
			$this->_request = $controller->request;
		} else {
			$this->_request = Request::createFromGlobals();
		}

		if ($controller && isset($controller->response)) {
			$this->_response = $controller->response;
		} else {
			$this->_response = new Response();
		}
	}

/**
 * Set the configuration for a specific top level key.
 *
 * ### Examples:
 *
 * Set a single config option for a key:
 *
 * {{{
 * $this->Cookie->configKey('User', 'expires', '+3 months');
 * }}}
 *
 * Set multiple options:
 *
 * {{{
 * $this->Cookie->configKey('User', [
 *   'expires', '+3 months',
 *   'httpOnly' => true,
 * ]);
 * }}}
 *
 * @param string $keyname The top level keyname to configure.
 * @param null|string|array $option Either the option name to set, or an array of options to set,
 *   or null to read config options for a given key.
 * @param string|null $value Either the value to set, or empty when $option is an array.
 * @return void
 */
	public function configKey($keyname, $option = null, $value = null) {
		if ($option === null) {
			$default = $this->_config;
			$local = isset($this->_keyConfig[$keyname]) ? $this->_keyConfig[$keyname] : [];
			return $local + $default;
		}
		if (!is_array($option)) {
			$option = [$option => $value];
		}
		$this->_keyConfig[$keyname] = $option;
	}

/**
 * Events supported by this component.
 *
 * @return array
 */
	public function implementedEvents() {
		return [];
	}

/**
 * Write a value to the response cookies.
 *
 * You must use this method before any output is sent to the browser.
 * Failure to do so will result in header already sent errors.
 *
 * @param string|array $key Key for the value
 * @param mixed $value Value
 * @return void
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::write
 */
	public function write($key, $value = null) {
		if (!is_array($key)) {
			$key = array($key => $value);
		}

		$keys = [];
		foreach ($key as $name => $value) {
			$this->_load($name);

			$this->_values = Hash::insert($this->_values, $name, $value);
			$parts = explode('.', $name);
			$keys[] = $parts[0];
		}

		foreach ($keys as $name) {
			$this->_write($name, $this->_values[$name]);
		}
	}

/**
 * Read the value of key path from request cookies.
 *
 * This method will also allow you to read cookies that have been written in this
 * request, but not yet sent to the client.
 *
 * @param string $key Key of the value to be obtained.
 * @return string or null, value for specified key
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::read
 */
	public function read($key = null) {
		$this->_load($key);
		return Hash::get($this->_values, $key);
	}

/**
 * Load the cookie data from the request and response objects.
 *
 * Based on the configuration data, cookies will be decrypted. When cookies
 * contain array data, that data will be expanded.
 *
 * @param string|array $key The key to load.
 * @return void
 */
	protected function _load($key) {
		$parts = explode('.', $key);
		$first = array_shift($parts);
		if (isset($this->_loaded[$first])) {
			return;
		}
		if (!isset($this->_request->cookies[$first])) {
			return;
		}
		$cookie = $this->_request->cookies[$first];
		$config = $this->configKey($first);
		$this->_loaded[$first] = true;
		$this->_values[$first] = $this->_decrypt($cookie, $config['encryption']);
	}

/**
 * Returns true if given key is set in the cookie.
 *
 * @param string $key Key to check for
 * @return bool True if the key exists
 */
	public function check($key = null) {
		if (empty($key)) {
			return false;
		}
		return $this->read($key) !== null;
	}

/**
 * Delete a cookie value
 *
 * You must use this method before any output is sent to the browser.
 * Failure to do so will result in header already sent errors.
 *
 * Deleting a top level key will delete all keys nested within that key.
 * For example deleting the `User` key, will also delete `User.email`.
 *
 * @param string $key Key of the value to be deleted
 * @return void
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::delete
 */
	public function delete($key) {
		$this->_load($key);

		$this->_values = Hash::remove($this->_values, $key);
		$parts = explode('.', $key);
		$top = $parts[0];

		if (isset($this->_values[$top])) {
			$this->_write($top, $this->_values[$top]);
		} else {
			$this->_delete($top);
		}
	}

/**
 * Set cookie
 *
 * @param string $name Name for cookie
 * @param string $value Value for cookie
 * @return void
 */
	protected function _write($name, $value) {
		$config = $this->configKey($name);
		$expires = new Time($config['expires']);

		$this->_response->cookie(array(
			'name' => $name,
			'value' => $this->_encrypt($value, $config['encryption']),
			'expire' => $expires->format('U'),
			'path' => $config['path'],
			'domain' => $config['domain'],
			'secure' => $config['secure'],
			'httpOnly' => $config['httpOnly']
		));
	}

/**
 * Sets a cookie expire time to remove cookie value.
 *
 * This is only done once all values in a cookie key have been
 * removed with delete.
 *
 * @param string $name Name of cookie
 * @return void
 */
	protected function _delete($name) {
		$config = $this->configKey($name);
		$expires = new Time('now');

		$this->_response->cookie(array(
			'name' => $name,
			'value' => '',
			'expire' => $expires->format('U') - 42000,
			'path' => $config['path'],
			'domain' => $config['domain'],
			'secure' => $config['secure'],
			'httpOnly' => $config['httpOnly']
		));
	}

/**
 * Encrypts $value using public $type method in Security class
 *
 * @param string $value Value to encrypt
 * @param string|bool $encrypt Encryption mode to use. False
 *   disabled encryption.
 * @return string Encoded values
 */
	protected function _encrypt($value, $encrypt) {
		if (is_array($value)) {
			$value = $this->_implode($value);
		}
		if (!$encrypt) {
			return $value;
		}
		$this->_checkCipher($encrypt);
		$prefix = "Q2FrZQ==.";
		if ($encrypt === 'rijndael') {
			$cipher = Security::rijndael($value, $this->_config['key'], 'encrypt');
		}
		if ($encrypt === 'aes') {
			$cipher = Security::encrypt($value, $this->_config['key']);
		}
		return $prefix . base64_encode($cipher);
	}

/**
 * Helper method for validating encryption cipher names.
 *
 * @param string $encrypt The cipher name.
 * @return void
 * @throws \RuntimeException When an invalid cipher is provided.
 */
	protected function _checkCipher($encrypt) {
		if (!in_array($encrypt, $this->_validCiphers)) {
			$msg = sprintf(
				'Invalid encryption cipher. Must be one of %s.',
				implode(', ', $this->_validCiphers)
			);
			throw new \RuntimeException($msg);
		}
	}

/**
 * Decrypts $value using public $type method in Security class
 *
 * @param array $values Values to decrypt
 * @param string|bool $mode Encryption mode
 * @return string decrypted string
 */
	protected function _decrypt($values, $mode) {
		if (is_string($values)) {
			return $this->_decode($values, $mode);
		}

		$decrypted = array();
		foreach ($values as $name => $value) {
			$decrypted[$name] = $this->_decode($value, $mode);
		}
		return $decrypted;
	}

/**
 * Decodes and decrypts a single value.
 *
 * @param string $value The value to decode & decrypt.
 * @param string|false $encrypt The encryption cipher to use.
 * @return string Decoded value.
 */
	protected function _decode($value, $encrypt) {
		if (!$encrypt) {
			return $this->_explode($value);
		}
		$this->_checkCipher($encrypt);
		$prefix = 'Q2FrZQ==.';
		$value = base64_decode(substr($value, strlen($prefix)));
		if ($encrypt === 'rijndael') {
			$value = Security::rijndael($value, $this->_config['key'], 'decrypt');
		}
		if ($encrypt === 'aes') {
			$value = Security::decrypt($value, $this->_config['key']);
		}
		return $this->_explode($value);
	}

/**
 * Implode method to keep keys are multidimensional arrays
 *
 * @param array $array Map of key and values
 * @return string A json encoded string.
 */
	protected function _implode(array $array) {
		return json_encode($array);
	}

/**
 * Explode method to return array from string set in CookieComponent::_implode()
 * Maintains reading backwards compatibility with 1.x CookieComponent::_implode().
 *
 * @param string $string A string containing JSON encoded data, or a bare string.
 * @return array Map of key and values
 */
	protected function _explode($string) {
		$first = substr($string, 0, 1);
		if ($first === '{' || $first === '[') {
			$ret = json_decode($string, true);
			return ($ret !== null) ? $ret : $string;
		}
		$array = array();
		foreach (explode(',', $string) as $pair) {
			$key = explode('|', $pair);
			if (!isset($key[1])) {
				return $key[0];
			}
			$array[$key[0]] = $key[1];
		}
		return $array;
	}

}
