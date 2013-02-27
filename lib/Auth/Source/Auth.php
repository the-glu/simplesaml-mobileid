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
     * 1100-9xxy-yyyy-yyyy where xx is International Prefix and yyy the number itself
     */
    private function getSuisseIDfrom($msisdn) {
        /* Ensure clean format */
        $suisseid = $this->getMSISDNfrom($msisdn, '00');
        
        /* Return empty if not valid US / World number */
        if (strlen($suisseid) != 12 && strlen($suisseid) != 13) return '';
        
        /* Set prefix for non american / american numbers */
        if (substr($suisseid, 0, 2) == '00')            // Non american number
            $suisseid = '1100-7' . substr($suisseid, 2);
        else                                            // -> american number needs one 0 more
            $suisseid = '1100-70' . substr($suisseid, 1);
        
        /* Add - */
        $suisseid = substr($suisseid, 0, 9) . '-' . substr($suisseid, 9, 4) . '-' . substr($suisseid, 13, 4);
        
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
        if ($this->mid_timeout_ws)	$mobileIdRequest->TimeOutWSRequest = (int)$this->mid_timeout_ws;
        
        /* Call Mobile ID */
        $mobileIdRequest->sendRequest($this->msisdn, $this->language, $this->message);
        if ($mobileIdRequest->response_error) {
            /* Define the error  */
            $erroris = $mobileIdRequest->response_status_message;
            /* Special handling for timeout */
            if ($mobileIdRequest->response_soap_fault_subcode === '208')
                $erroris = 'EXPIRED_TRANSACTION';

            /* Filter the configuration errors */
            switch ($erroris) {
                case 'WRONG_PARAM';
                case 'MISSING_PARAM';
                case 'WRONG_DATA_LENGTH';
                case 'INAPPROPRIATE_DATA';
                case 'INCOMPATIBLE_INTERFACE';
                case 'UNSUPPORTED_PROFILE';
                case 'UNAUTHORIZED_ACCESS';
                    SimpleSAML_Logger::warning('MobileID: error in service call ' . var_export($erroris, TRUE));
                    throw new Exception('MobileID: error in service call ' . var_export($erroris, TRUE));
                    break;
            }
            
            /* Filter the valid ones for dictionnaries translations */
            switch($erroris) {
                case 'UNKNOWN_CLIENT';
                case 'EXPIRED_TRANSACTION';
                case 'USER_CANCEL';
                case 'PIN_NR_BLOCKED';
                case 'CARD_BLOCKED';
                case 'REVOKED_CERTIFICATE';
                    break;
                // All other errors are mapped to INTERNAL_ERROR
                default:
                    $erroris = 'INTERNAL_ERROR';
                    break;
            }

            /* Log the details */
            SimpleSAML_Logger::warning('MobileID: error in service call ' . var_export($mobileIdRequest->response_status_message, TRUE) . ' mapped to ' . var_export($erroris, TRUE));

            /* Set the error */
            throw new SimpleSAML_Error_Error($erroris);
        }

        /* Create the attribute array of the user. */
        $attributes = array(
            'uid' => array($this->uid),
            'mobile' => array($this->getMSISDNfrom($this->msisdn, '00')),
            'noredupersonnin' => array($this->getSuisseIDfrom($this->msisdn)),
            'edupersontargetedid' => array($mobileIdRequest->data_response_certificate['subject']['serialNumber']),
            'preferredLanguage' => array($this->language),
        );
        
        /* Return the attributes. */
        return $attributes;
	}    
}

?>
