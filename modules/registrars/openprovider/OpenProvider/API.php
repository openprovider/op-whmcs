<?php

namespace OpenProvider;

require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'idna_convert.class.php';

class API
{

    protected $api;
    protected $request;
    protected $url;
    protected $error            =   null;
    protected $timeout          =   null;
    protected $debug            =   null;
    protected $username         =   null;
    protected $password         =   null;
    
    public function __construct($params, $debug = 0)
    {
        $this->url = $params['OpenproviderAPI'];
        $this->timeout = \OpenProvider\APIConfig::$curlTimeout;
        $this->request = new \OpenProvider\Request();

        $this->request->setAuth(array(
            'username' => $params["Username"],
            'password' => $params["Password"],
        ));

        $this->username     =   $params['Username'];
        $this->password     =   $params['Password'];
        
        $this->debug        =   $debug;
    }

    public function searchCustomerInOPdatabase(\OpenProvider\Customer $customer)
    {
        $searchCustomerArray = array
        (
            'lastNamePattern'       =>  $customer->name['lastName'],
            'companyNamePattern'    =>  $customer->companyName,
            'emailPattern'          =>  $customer->email,
        );
        return $this->sendRequest('searchCustomerRequest', $searchCustomerArray);
    }
    
    protected function modifyCustomerInOPdatabase(\OpenProvider\Customer $customer)
    {
        $args = $customer;
        $this->sendRequest('modifyCustomerRequest', $args);
    }

    public function createCustomerInOPdatabase(\OpenProvider\Customer $customer)
    {
        $args = $customer;
        $resutl = $this->sendRequest('createCustomerRequest', $args);
        $customer->handle = $resutl['handle'];
        
        return $resutl;
    }

    public function sendRequest($requestCommand, $args = null)
    {
        // prepare request
        $this->request->setCommand($requestCommand);
        
        // prepare args
        if (isset($args))
        {
            $args = json_decode(json_encode($args), true);
            
            $idn = new \idna_convert();
            
            // idn
            if (isset($args['domain']['name']) && isset($args['domain']['extension']))
            {
                // UTF-8 encoding
                if (!preg_match('//u', $args['domain']['name']))
                {
                    $args['domain']['name'] = utf8_encode($args['domain']['name']);
                }
                
                $args['domain']['name'] = $idn->encode($args['domain']['name']);
            }
            elseif (isset ($args['namePattern']))
            {
                $namePatternArr = explode('.', $args['namePattern'], 2);
                $tmpDomainName = $namePatternArr[0];
                
                // UTF-8 encoding
                if (!preg_match('//u', $tmpDomainName))
                {
                    $tmpDomainName = utf8_encode($tmpDomainName);
                }
                
                $tmpDomainName = $idn->encode($tmpDomainName);
                $args['namePattern'] = $tmpDomainName . '.' . $namePatternArr[1];
            }
            elseif (isset ($args['name']) && !is_array($args['name'])) 
            {
                // UTF-8 encoding
                if (!preg_match('//u', $args['name']))
                {
                    $args['name'] = utf8_encode($args['name']);
                }
                
                $args['name'] = $idn->encode($args['name']);
            }
            
            $this->request->setArgs($args);
        }

        // send request
        $result = $this->process($this->request);
        
        $resultValue = $result->getValue();

        $faultCode = $result->getFaultCode();

        if ($faultCode != 0)
        { 
            $msg = $result->getFaultString();
            if ($value = $result->getValue())
            {
                if(is_array($value))
                {
                    $msg .= ':<br> '.$value['description'].' '.(isset($value['options']) ? '('.implode(',', $value['options']).')' : '' );
                }
                else
                {
                    $msg .= ':<br> '.$value;
                }
            }
            
            throw new \Exception($msg, $faultCode);
        }

        return $resultValue;
    }

    protected function process(\OpenProvider\Request $r)
    {
        if ($this->debug)
        {
            echo $r->getRaw() . "\n";
        }

        $postValues = $r->getRaw();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postValues);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $ret = curl_exec($ch);

        

        $errno = curl_errno($ch);
        $this->error = curl_error($ch);
        
        // log message
        logModuleCall(
                'OpenProvider NL',
                $r->getCommand(),
                array(
                    'postValues' => $postValues,
                ),
                array(
                    'curlResponse' => $ret,
                    'curlErrNo'    => $errno,
                    'errorMessage' => $this->error,
                ), 
                null,
                array(
                    $this->password
                ));
        
        if (!$ret)
        {
            throw new \Exception('Bad reply');
        }
        
        curl_close($ch);

        if ($errno)
        {
            return false;
        }
        
        if ($this->debug)
        {
            echo $ret . "\n";
        }
        
        return new \OpenProvider\Reply($ret);
    }

    /**
     * 
     * @param array $createHandleArray
     * @return string Openprovider Customer Handle
     */
    public function createCustomerRequest($createHandleArray)
    {
        $result = $this->sendRequest('createCustomerRequest', $createHandleArray);
        return $result['handle'];
    }

    public function searchCustomerRequest($searchCustomerArray)
    {
        return $this->sendRequest('searchCustomerRequest', $searchCustomerArray);
    }

    protected function searchZoneDnsRequest(\OpenProvider\Domain $domain)
    {
        $args = array(
            'namePattern' => $domain->getFullName(),
            'type'        => 'master',
        );
        
        return $this->sendRequest('searchZoneDnsRequest', $args);
    }
    
    public function registerDomain(\OpenProvider\DomainRegistration $domainRegistration)
    {
        // check if zone exists
        $zoneResult = $this->searchZoneDnsRequest($domainRegistration->domain);
        
        if (0 == $zoneResult['total'])
        {
            // create a new DNS zone object
            $zoneArgs = array
            (
                'domain'    =>  $domainRegistration->domain, 
                'type'      =>  'master', 
                'templateName' => 'default'
            );
            $this->sendRequest('createZoneDnsRequest', $zoneArgs);
        }
        
        // register
        return $this->sendRequest('createDomainRequest', $domainRegistration);
    }

    /**
     * Get domain name servers
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function getNameservers(\OpenProvider\Domain $domain)
    {
        $result = $this->retrieveDomainRequest($domain);
   
        $nameservers    =   array();
        foreach($result['nameServers'] as $ns)
        {  
            $nameservers[] = (empty($ns['name']) ? $ns['ip'] : $ns['name']);
        }
        
        return $nameservers;
    }

    /**
     * Get registrar lock status
     * @param \OpenProvider\Domain $domain
     * @return bool
     */
    public function getRegistrarLock(\OpenProvider\Domain $domain)
    {
        $result         =   $this->retrieveDomainRequest($domain);
        $lockedStatus   =   $result['isLocked'] ? true : false;

        return $lockedStatus;
    }

    /**
     * 
     * @param \OpenProvider\Domain $domain
     * @param type $nameServers
     * @return type
     */
    public function saveNameservers(\OpenProvider\Domain $domain, $nameServers)
    {
        $args = array
        (
            'domain'        =>  $domain,
            'nameServers'   =>  $nameServers,
        );

        return $this->sendRequest('modifyDomainRequest', $args);
    }

    /**
     * Save registrar lock
     * @param \OpenProvider\Domain $domain
     * @param type $lockStatus
     * @return type
     */
    public function saveRegistrarLock(\OpenProvider\Domain $domain, $lockStatus)
    {
        $args = array
        (
            'domain'    =>  $domain,
            'isLocked'  =>  $lockStatus,
        );

        return $this->sendRequest('modifyDomainRequest', $args);
    }

    /**
     * Save zone records
     * @param \OpenProvider\Domain $domain
     * @param type $dnsRecordsArr
     */
    public function saveDNS(\OpenProvider\Domain $domain, $dnsRecordsArr)
    {
        $searchArgs = array
        (
            'namePattern' => $domain->getFullName(),
        );
        $result = $this->sendRequest('searchZoneDnsRequest', $searchArgs);

        $args = array
        (
            'domain' => $domain,
            'type' => 'master',
            'records' => $dnsRecordsArr,
        );
        if ($result['total'] > 0)
        {
            $this->sendRequest('modifyZoneDnsRequest', $args);
        }
        else
        {
            $this->sendRequest('createZoneDnsRequest', $args);
        }
    }
    
    /**
     * Delete zone records
     * @param \OpenProvider\Domain $domain
     */
    public function deleteDNS(\OpenProvider\Domain $domain)
    {
        $args = array(
            'domain' => $domain,                
        );
        
        $this->sendRequest('deleteZoneDnsRequest', $args);
    }

    /**
     * Get zone records
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function getDNS(\OpenProvider\Domain $domain)
    {
        $searchArgs = array
        (
            'namePattern' => $domain->getFullName(),
        );
        $result = $this->sendRequest('searchZoneDnsRequest', $searchArgs);
 
        if ($result['total'] > 0)
        {
            $retrieveArgs = array(
                'name' => $domain->getFullName(),
                'withHistory' => 0
            );
            return $this->sendRequest('retrieveZoneDnsRequest', $retrieveArgs);
        }
        else
        {
            return null;
        }
    }

    /**
     * Delete domain
     * @param \OpenProvider\Domain $domain
     */
    public function requestDelete(\OpenProvider\Domain $domain)
    {
        $args = array
        (
            'domain' => $domain,
        );

        // zone
        $this->deleteDNS($domain);
        
        // domain
        $this->sendRequest('deleteDomainRequest', $args);
    }

    /**
     * Get domain contact details
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function getContactDetails(\OpenProvider\Domain $domain)
    {
        $domainInfo = $this->retrieveDomainRequest($domain);
        
        $contacts   =   array();
        foreach(\OpenProvider\APIConfig::$handlesNames as $key => $name)
        {
            if(empty($domainInfo[$key]))
            {
                continue;
            }
            
            $contacts[$name]    =   $this->retrieveCustomerRequest($domainInfo[$key]);
        }
        
        return $contacts;
    }

    /**
     * Return array with contact information for given handle
     * 
     * @param string $handle Customer handle
     * @return array
     */
    protected function retrieveCustomerRequest($handle)
    {
        $args = array(
            'handle' => $handle,
        );
        $contact = $this->sendRequest('retrieveCustomerRequest', $args);

        $customerInfo = array();

        $customerInfo['First Name'] = $contact['name']['firstName'];
        $customerInfo['Last Name'] = $contact['name']['lastName'];
        $customerInfo['Company Name'] = $contact['companyName'];
        $customerInfo['Email Address'] = $contact['email'];
        $customerInfo['Address'] = $contact['address']['street'] . ' ' .
                $contact['address']['number'] . ' ' .
                $contact['address']['suffix'];
        $customerInfo['City'] = $contact['address']['city'];
        $customerInfo['State/Region'] = $contact['address']['state'];
        $customerInfo['Zip Code'] = $contact['address']['zipcode'];
        $customerInfo['Country'] = $contact['address']['country'];
        $customerInfo['Phone Number'] = $contact['phone']['countryCode'] . '.' .
                $contact['phone']['areaCode'] .
                $contact['phone']['subscriberNumber'];

        return $customerInfo;
    }

    public function SaveContactDetails(\OpenProvider\Domain $domain, Array $contacts, $domainId)
    {
        $handle     =   new \OpenProvider\Handles($domainId);
        $handles    =   array_filter($handle->getById($domainId));
        
        if(empty($handles))
        {
            $handle->importToWHMCS($this, $domain, $domainId, false);
        }
        
        $handles    =   array_filter($handle->getById($domainId));
        if(empty($handles))
        {
            throw new \Exception('Cannot read contact handlers');
        }
        
        $domainInfo = $this->retrieveDomainRequest($domain);
        
        foreach($contacts as $contactType => $contactValues)
        {
            $contactValues->handle  =   $handles[$contactType];
            
            
            $this->modifyCustomerContactDetails($domain, $domainInfo, $contactValues, $contactType);
        }
    }

    protected function modifyCustomerContactDetails(\OpenProvider\Domain $domain, $domainInfo, \OpenProvider\Customer $customer, $type = '')
    {

        $opCustomer = $this->retrieveCustomerRequest($customer->handle);        

        
        // if name & company name are the same, then call modifyCustomerRequest only
        if ($opCustomer['First Name'] == $customer->name->firstName &&
            $opCustomer['Last Name'] == $customer->name->lastName &&
            $opCustomer['Company Name'] == $customer->companyName)
        {
            $this->modifyCustomerInOPdatabase($customer);
        }
        else
        {
            if(!$type)
            {
                throw new Exception('Contact type is not set');
            }
 
            // create customer
            $createResult = $this->createCustomerInOPdatabase($customer);
            $handle = $createResult['handle'];

            $args = array
            (
                'domain'    =>  $domain,
                $type       =>  $handle  
            );
            
            sleep(3);
            
            $this->sendRequest('modifyDomainRequest', $args);
        }
    }

    /**
     * Transfer domain
     * @param \OpenProvider\DomainTransfer $domainTransfer
     */
    public function transferDomain(\OpenProvider\DomainTransfer $domainTransfer)
    {
        // check if zone exists
        $zoneResult = $this->searchZoneDnsRequest($domainTransfer->domain);
        
        if (0 == $zoneResult['total'])
        {
            // create a new DNS zone object
            $zoneArgs = array
            (
                'domain'    =>  $domainTransfer->domain, 
                'type'      =>  'master', 
            );
            $this->sendRequest('createZoneDnsRequest', $zoneArgs);
        }
        
        $this->sendRequest('transferDomainRequest', $domainTransfer);
    }

    
    /**
     * Renew domain
     * @param \OpenProvider\Domain $domain
     * @param type $period
     */
    public function renewDomain(\OpenProvider\Domain $domain, $period)
    {
        $args = array
        (
            'domain' => $domain,
            'period' => $period,
        );

        $this->sendRequest('renewDomainRequest', $args);
    }

    /**
     * Get domain epp code
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function getEPPCode(\OpenProvider\Domain $domain)
    {
        $domainInfo = $this->retrieveDomainRequest($domain);
        return $domainInfo['authCode'];
    }

    /**
     * Creaat domain name server
     * @param \OpenProvider\DomainNameServer $nameServer
     */
    public function registerNameserver(\OpenProvider\DomainNameServer $nameServer)
    {
        $this->sendRequest('createNsRequest', $nameServer);
    }

    /**
     * Delete domain name server
     * @param \OpenProvider\DomainNameServer $nameServer
     */
    public function deleteNameserver(\OpenProvider\DomainNameServer $nameServer)
    {
        $this->sendRequest('deleteNsRequest', $nameServer);
    }

    
    /**
     * 
     * @param string $request
     * @param \OpenProvider\DomainNameServer $nameServer
     * @param string $currentIp
     * @throws \Exception
     */
    public function nameserverRequest($request, \OpenProvider\DomainNameServer $nameServer, $currentIp = null)
    {
        if ('modify' == $request)
        {
            // check current IP address
            $retrieveResult = $this->sendRequest('retrieveNsRequest', $nameServer);
            if ($retrieveResult['ip'] != $currentIp)
            {
                throw new \Exception('Current IP Address is incorrect');
            }
        }
        
        $this->sendRequest($request . 'NsRequest', $nameServer);
    }

    /**
     * Get information about domain
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function retrieveDomainRequest(\OpenProvider\Domain $domain)
    {
        $args = array
        (
            'domain' => $domain,
        );

        return $this->sendRequest('retrieveDomainRequest', $args);
    }

    /**
     * Enable/Disable domain auto renew
     * @param \OpenProvider\Domain $domain
     * @param type $autoRenew
     * @return type
     */
    public function setAutoRenew(\OpenProvider\Domain $domain, $autoRenew)
    {
        $args = array
        (
            $domain     =>  $domain,
            'autorenew' =>  $autoRenew,
        );
        
        return $this->sendRequest('modifyDomainRequest', $args);
    }
    
    /**
     * Check domain availability
     * @param \OpenProvider\Domain $domain
     * @return type
     */
    public function checkDomain(\OpenProvider\Domain $domain)
    {
        $args = array
        (
            'domains'               =>  array
            (
                'item'              =>  array
                (
                    'name'          =>  $domain->name,
                    'extension'     =>  $domain->extension
                )
            )
        );

        return $this->sendRequest('checkDomainRequest', $args);
    }
    
    /**
     * Search for DNS template names
     * @return type
     */
    public function searchTemplateDnsRequest()
    {
        return $this->sendRequest('searchTemplateDnsRequest');
    }
    
    public function tryAgain(\OpenProvider\Domain $domain)
    {
        $args = array
        (
            'domain'    =>  $domain
        );
                
        return $this->sendRequest('tryAgainDomainRequest', $args);
    }
}
