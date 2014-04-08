<?php
/**
 * @version     1.0.0
 * @package     simpleSAMLphp-mobileid
 * @copyright   Copyright (C) 2012. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.md
 * @author      Swisscom (Schweiz AG)
 */

class sspmod_mobileid_Auth_Source_Auth extends SimpleSAML_Auth_Source {

    /* The string used to identify our states. */
	const STAGEID = 'sspmod_mobileid_Auth_Source_Auth.state';
    /* The key of the AuthId field in the state. */
	const AUTHID = 'sspmod_mobileid_Auth_Source_Auth.AuthId';

	/* The mobile id related stuff. */
    private $hosturi;
    private $uid;
    private $msisdn;
    private $language = 'en';
    private $message = 'Login with Mobile ID?';
    private $ap_id;
    private $ap_pwd = "disabled";
    private $cert_file;
    private $cert_key;
    private $mid_ca;
    private $mid_ocsp;
    private $mid_timeout_ws;
    private $mid_timeout_mid;
    private $remember_msisdn = FALSE;
    private $curl_proxy = '';

	/**
	 * Constructor for this authentication source.
	 *
	 * @param array $info  Information about this authentication source.
	 * @param array $config  Configuration.
	 */
	public function __construct($info, $config) {
		assert('is_array($info)');
		assert('is_array($config)');

		/* Call the parent constructor first, as required by the interface. */
		parent::__construct($info, $config);

		$globalConfig = SimpleSAML_Configuration::getInstance();
		$certdir = $globalConfig->getPathValue('certdir', 'cert/');

        /* Mandatory options */
        if (!isset($config['hosturi']))
			throw new Exception('MobileID: Missing or invalid hosturi option in config.');
		$this->hosturi = $config['hosturi'];

        if (!isset($config['ap_id']))
			throw new Exception('MobileID: Missing or invalid ap_id option in config.');
		$this->ap_id = $config['ap_id'];
        
        if (!isset($config['ap_pwd']))
			throw new Exception('MobileID: Missing or invalid ap_pwd option in config.');
		$this->ap_pwd = $config['ap_pwd'];

        if (!isset($config['cert_file']))
			throw new Exception('MobileID: Missing or invalid cert_file option in config.');
        $this->cert_file = SimpleSAML_Utilities::resolvePath($config['cert_file'], $certdir);
        if (!file_exists($this->cert_file))
            throw new Exception('MobileID: Missing or invalid cert_file option in config: ' . $this->cert_file);

        if (!isset($config['cert_key']))
			throw new Exception('MobileID: Missing or invalid cert_key option in config.');
        $this->cert_key = SimpleSAML_Utilities::resolvePath($config['cert_key'], $certdir);
        if (!file_exists($this->cert_key))
            throw new Exception('MobileID: Missing or invalid cert_key option in config: ' . $this->cert_key);

        if (!isset($config['mid_ca']))
			throw new Exception('MobileID: Missing or invalid mid_ca option in config.');
        $this->mid_ca = SimpleSAML_Utilities::resolvePath($config['mid_ca'], $certdir);
        if( !file_exists($this->mid_ca))
            throw new Exception('MobileID: Missing or invalid mid_ca option in config: ' . $this->mid_ca);
        
        if (!isset($config['mid_ocsp']))
			throw new Exception('MobileID: Missing or invalid mid_ocsp option in config.');
        $this->mid_ocsp = SimpleSAML_Utilities::resolvePath($config['mid_ocsp'], $certdir);
        if (!file_exists($this->mid_ocsp))
            throw new Exception('MobileID: Missing or invalid mid_ocsp option in config: ' . $this->mid_ocsp);
                
        /* Optional options */
        if (isset($config['default_lang']))
            $this->language = $config['default_lang'];
        
        if (isset($config['timeout_ws']))
            $this->mid_timeout_ws = $config['timeout_ws'];
        
        if (isset($config['timeout_mid']))
            $this->mid_timeout_mid = $config['timeout_mid'];

        if (isset($config['remember_msisdn']))
            $this->remember_msisdn = $config['remember_msisdn'];
        if (isset($config['proxy']))
            $this->curl_proxy = $config['proxy'];
	}

	/**
	 * Initialize login.
	 *
	 * This function saves the information about the login, and redirects to a login page.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public function authenticate(&$state) {
		assert('is_array($state)');

		/* We are going to need the authId in order to retrieve this authentication source later. */
		$state[self::AUTHID] = $this->authId;

		/* Remember mobile number. */
		if ($this->remember_msisdn) {
			$state['remember_msisdn'] = $this->remember_msisdn;
		}
		
		$id = SimpleSAML_Auth_State::saveState($state, self::STAGEID);

		$url = SimpleSAML_Module::getModuleURL('mobileid/mobileidlogin.php');
		SimpleSAML_Utilities::redirect($url, array('AuthState' => $id));
	}
	
	/**
	 * Handle login request.
	 *
	 * This function is used by the login form when the users a Mobile ID number.
	 * On success, it will not return. On Mobile ID failure, it will return the error code.
     * Other failures will throw an exception.
	 *
	 * @param string $authStateId  The identifier of the authentication state.
	 * @param string $msisdn  The Mobile ID entered.
     * @param string $language  The language of the communication.
     * @param string $message  The message to be communicated.
	 * @return string  Error code in the case of an error.
	 */
	public static function handleLogin($authStateId, $msisdn, $language, $message) {
		assert('is_string($authStateId)');
		assert('is_string($msisdn)');
        assert('is_string($language)');
        assert('is_string($message)');

		/* Retrieve the authentication state. */
		$state = SimpleSAML_Auth_State::loadState($authStateId, self::STAGEID);

		/* Find authentication source. */
		assert('array_key_exists(self::AUTHID, $state)');
		$source = SimpleSAML_Auth_Source::getById($state[self::AUTHID]);
		if ($source === NULL) {
			throw new Exception('Could not find authentication source with id ' . $state[self::AUTHID]);
		}

		/* $source now contains the authentication source on which authenticate() was called
		 * We should call login() on the same authentication source.
		 */
		try {
			/* Attempt to log in. */
			$attributes = $source->login($msisdn, $language, $message);
		} catch (SimpleSAML_Error_Error $e) {
            /* Login failed. Return the error code to the login form */
			return $e->getErrorCode();
		}
        
		/* Save the attributes we received from the login-function in the $state-array. */
		assert('is_array($attributes)');
		$state['Attributes'] = $attributes;
        
        /* Set the AuthnContext */
        $state['saml:AuthnContextClassRef'] = 'urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorContract';
        
        /* Return control to simpleSAMLphp after successful authentication. */
		SimpleSAML_Auth_Source::completeAuth($state);
	}
    
    /* A helper function for setting the right user id.
     *
     * Ensures international format with specified prefix (+ or 00) and no spaces
     */
    private function getMSISDNfrom($uid, $prefix = '00') {
        $uid = preg_replace('/\s+/', '', $uid);         // Remove all whitespaces
        $uid = str_replace('+', '00', $uid);            // Replace all + with 00
        $uid = preg_replace('/\D/', '', $uid);          // Remove all non-digits
        if (strlen($uid) > 5) {                         // Still something here */
            if ($uid[0] == '0' && $uid[1] != '0')           // Add implicit 41 if starting with one 0
                $uid = '41' . substr($uid, 1);
            $uid = ltrim($uid, '0');                        // Remove all leading 0
        }
        $uid = $prefix . $uid;                           // Add the defined prefix
        
        return $uid;
    }
    
    /* A helper function for generating a SuisseID number.
     *
     * Based on MSISDN like 0041792080350 we generate a SuisseID conform number
     * for Switzerland and Lichtenstein only. For the others an empty string will be returned.
     */
    private function getSuisseIDfrom($msisdn) {
        /* Ensure clean format */
        $msisdn = $this->getMSISDNfrom($msisdn, '00');
        
        /* Country based whitelisting */
        if (substr($msisdn, 0, 4) == '0041') {          // Switzerland
            $msisdn = str_pad(substr($msisdn, 4), 9, "0", STR_PAD_LEFT);
            $suisseid = '1100741' . $msisdn;
            }
        elseif (substr($msisdn, 0, 5) == '00423') {     // Lichtenstein
            $msisdn = str_pad(substr($msisdn, 5), 8, "0", STR_PAD_LEFT);
            $suisseid = '11007423' . $msisdn;
            }
        else return('');                                // Blacklisted

        /* Check valid number */
        if (strlen($suisseid) != 16) return('');

        /* Add - */
        $suisseid = substr($suisseid, 0, 4) . '-' . substr($suisseid, 4, 4) . '-' . substr($suisseid, 8, 4) . '-' . substr($suisseid, 12, 4);
        
        return $suisseid;
    }
    
    /* The login function.
     *
	 * @param string $msisdn  The Mobile ID entered.
	 * @return string  Attributes.
     */
	protected function login($username, $language, $message) {
		assert('is_string($username)');
		assert('is_string($language)');
		assert('is_string($message)');
        
		require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/libextinc/mobileid.php';
		$attributes = array();

        /* Language and Message. */
        $this->language = $language;
        $this->message  = $this->hosturi . ': ' . $message;

		/* uid and msisdn defaults to username. */
		$this->uid    = $username;
        $this->msisdn = $this->getMSISDNfrom($username, '+');
        SimpleSAML_Logger::info('MobileID: Login of ' . var_export($this->uid, TRUE) . ' as ' . var_export($this->msisdn, TRUE));
        SimpleSAML_Logger::info('MobileID:   Message ' . var_export($this->message, TRUE) . ' in ' . var_export($this->language, TRUE));
        
        /* New instance of the Mobile ID class */
        $mobileIdRequest = new mobileid($this->ap_id, $this->ap_pwd);
        $mobileIdRequest->cert_file = $this->cert_file;
        $mobileIdRequest->cert_key  = $this->cert_key;
        $mobileIdRequest->cert_ca   = $this->mid_ca;
        $mobileIdRequest->ocsp_cert = $this->mid_ocsp;
        if ($this->mid_timeout_mid) $mobileIdRequest->TimeOutMIDRequest = (int)$this->mid_timeout_mid;
        if ($this->mid_timeout_ws) $mobileIdRequest->TimeOutWSRequest = (int)$this->mid_timeout_ws;
        if ($this->curl_proxy) $mobileIdRequest->curl_proxy = $this->curl_proxy;
        
        /* Call Mobile ID */
        $mobileIdRequest->sendRequest($this->msisdn, $this->language, $this->message);
                
        /* Error handling */
        if ($mobileIdRequest->response_error) {
            $erroris = 'DEFAULT';
            /* Get error code from status code or fault subcode */
            if (strlen($mobileIdRequest->response_mss_status_code))
                $erroris = $mobileIdRequest->response_mss_status_code;
            if (strlen($mobileIdRequest->response_soap_fault_subcode))
                $erroris = $mobileIdRequest->response_soap_fault_subcode;
            
            /* Define error text */
            $errortxt = $erroris . ' -> ' . $mobileIdRequest->response_status_message;
            
            /* Filter the configuration errors */
            $exception_code = array("102", "103", "104", "107", "108", "109");
            if (in_array($erroris, $exception_code)) {
                SimpleSAML_Logger::warning('MobileID: error in service call ' . var_export($errortxt, TRUE));
                throw new Exception('MobileID: error in service call ' . var_export($errortxt, TRUE));
            }
 
            /* Filter the dictionnaries errors and map the rest to default */
            $dico_code = array("101", "105", "208", "209", "401", "402", "403", "404", "406", "422", "501", "503");
            if (!in_array($erroris, $dico_code)) {
                $erroris = 'DEFAULT';
                $errortxt = $errortxt . ' mapped to ' . $erroris;
            }

            /* Log the details */
            SimpleSAML_Logger::warning('MobileID: error in service call ' . var_export($errortxt, TRUE));

            /* Set the error */
            throw new SimpleSAML_Error_Error($erroris);
        }

        /* Create the attribute array of the user. */
        $attributes = array(
            'uid' => array($this->uid),
            'mobile' => array($this->getMSISDNfrom($this->msisdn, '00')),
            'pseudonym' => array($this->getSuisseIDfrom($this->msisdn)),
            'serialNumber' => array($mobileIdRequest->data_response_certificate['subject']['serialNumber']),
            'preferredLanguage' => array($this->language),
            /* TODO: Remove backwards compatibility attributes */
            'noredupersonnin' => array($this->getSuisseIDfrom($this->msisdn)),
            'edupersontargetedid' => array($mobileIdRequest->data_response_certificate['subject']['serialNumber']),
            /* TODO: End of attributes to be removed */
        );
        
        /* Return the attributes. */
        return $attributes;
	}    
}

?>
