<?php

require_once __DIR__.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'idna_convert.class.php';

/**
 * Autolaod
 * @param type $class_name
 */

spl_autoload_register(function ($className) 
{
    $className  =   implode(DIRECTORY_SEPARATOR, explode('\\', $className));
    
    if(file_exists((__DIR__).DIRECTORY_SEPARATOR.$className.'.php'))
    {
        require_once (__DIR__).DIRECTORY_SEPARATOR.$className.'.php';
    }
}); 


function openprovider_getConfigArray($params = array())
{
    // creating the necessary tables
    \OpenProvider\APITools::createOpenprovidersTable();
    \OpenProvider\APITools::createCustomFields();
    
    $configarray = array
    (
        "OpenproviderAPI"   => array
        (
            "Type"          => "text", 
            "Size"          => "60", 
            "Description"   => "Openprovider API URL",
        ),
        "Username"          => array
        (
            "Type"          => "text", 
            "Size"          => "20", 
            "Description"   => "Openprovider login",
        ),
        "Password"          => array
        (
            "Type"          => "password", 
            "Size"          => "20", 
            "Description"   => "Openprovider password",
        ),
        "useLocalHanlde"    => array 
        (
            "FriendlyName"  => "Ascribe already used contacts to a new domain",
            "Type"          => "yesno",
            "Description"   => "&zwnj;",
        ), 
    );

    $x = explode(DIRECTORY_SEPARATOR, $_SERVER['SCRIPT_FILENAME']);
    $filename = end($x);
    if(isset($_REQUEST) && $_REQUEST['action'] == 'save' && $filename == 'configregistrars.php')
    {
        foreach($_REQUEST as $key => $val)
        {
            if(isset($configarray[$key]))
            {
                $params[$key]   =   $val;
            }
        }
    }
    
    if(isset($params['Password']) && isset($params['Username']) && isset($params['OpenproviderAPI']))
    {
        try
        { 
            $api                =   new \OpenProvider\API($params);
            $templates          =   $api->searchTemplateDnsRequest();
            
            if(isset($templates['total']) && $templates['total'] > 0)
            {
                $tpls   =   'None,';
                foreach($templates['results'] as $template)
                {
                    $tpls .= $template['name'].',';
                }
                $tpls = trim($tpls,',');
                
                $configarray['dnsTemplate']  =   array 
                (
                    "FriendlyName"  =>  "DNS Template",
                    "Type"          =>  "dropdown",
                    "Description"   =>  "",
                    "Options"       =>  $tpls
                );
            }
        } 
        catch (Exception $ex) 
        {
            //do nothing
        }
    }
    
    return $configarray;
}


/**
 * 
 * @param type $params
 * @return type
 */
function openprovider_RegisterDomain($params)
{
    $values = array();
    
    try
    {
        $encodedDomainName = OpenProvider\APITools::getEncodedDomainName($params['domainname']);
        
        $domain             =   new \OpenProvider\Domain();
        $domain->extension  =   $params['tld'];
        $domain->name       =   $encodedDomainName;//$params['sld'];
        $nameServers        =   \OpenProvider\APITools::createNameserversArray($params);
        $createNewHandles   =   false;
        $useLocalHandle     =   isset($params['useLocalHanlde']) && $params['useLocalHanlde'];
        
        if ($useLocalHandle)
        {
            // read user's handles
            $handles        =   \OpenProvider\APITools::readCustomerHandles($params['userid']);
            
            if ($handles->ownerHandle && $handles->adminHandle && $handles->techHandle && $handles->billingHandle)
            {
                $ownerHandle    =   $handles->ownerHandle;
                $adminHandle    =   $handles->adminHandle;
                $techHandle     =   $handles->techHandle;
                $billingHandle  =   $handles->billingHandle;
            }
            else
            {
                $createNewHandles = true;
            }
        }
        
//        if($params['tld'] == 'es' || $params['tld'] == 'cat')
//        {
            
            $fields         = \OpenProvider\APITools::getClientCustomFields($params['customfields']);
            if(!is_object($additionalData))
                $additionalData =   new \OpenProvider\CustomerAdditionalData();
            
            
            if($fields['ownerType'] == 'Individual')
            {
                $additionalData->set('socialSecurityNumber', $fields['socialSecurityNumber']);
                $additionalData->set('passportNumber', $fields['passportNumber']);
            } elseif($fields['ownerType'] == 'Company')
            {
                $additionalData->set('companyRegistrationNumber', $fields['companyRegistrationNumber']);
                $additionalData->set('VATNumber', $fields['VATNumber']);
            }
            
//        }
        
        if ($params['tld'] == 'ca') {
            $handles = \OpenProvider\APITools::getHandlesForDomainId($params['domainid']);
        }
        
        if (empty($handles) || $params['tld'] != 'ca') {
            if (!$useLocalHandle || $createNewHandles) {
                $ownerCustomer      =   new \OpenProvider\Customer($params['original']);
                $ownerCustomer      ->  additionalData = $additionalData;
                $ownerHandle        =   \OpenProvider\APITools::createCustomerHandle($params, $ownerCustomer);

                $adminCustomer      =   new \OpenProvider\Customer($params['original']);
                $adminCustomer      ->  additionalData = $additionalData;
                $adminHandle        =   \OpenProvider\APITools::createCustomerHandle($params, $adminCustomer);

                $techCustomer       =   new \OpenProvider\Customer($params['original']);
                $techCustomer       ->  additionalData = $additionalData;
                $techHandle         =   \OpenProvider\APITools::createCustomerHandle($params, $techCustomer);

                $billingCustomer    =   new \OpenProvider\Customer($params['original']);
                $billingCustomer    ->  additionalData = $additionalData;
                $billingHandle      =   \OpenProvider\APITools::createCustomerHandle($params, $billingCustomer);

                $handles = array();
                $handles['domainid'] = $params['domainid'];
                $handles['ownerHandle'] = $ownerHandle;
                $handles['adminHandle'] = $adminHandle;
                $handles['techHandle'] = $techHandle;
                $handles['billingHandle'] = $billingHandle;
                $handles['resellerHandle'] = '';
                
                if ($params['tld'] == 'ca') {
                    \OpenProvider\APITools::saveNewHandles($handles);
                }
                
            }
        }
        
        // domain registration
        $domainRegistration                 =   new \OpenProvider\DomainRegistration();
        $domainRegistration->domain         =   $domain;
        $domainRegistration->period         =   $params['regperiod'];
        $domainRegistration->ownerHandle    =   $handles['ownerHandle'];
        $domainRegistration->adminHandle    =   $handles['adminHandle'];
        $domainRegistration->techHandle     =   $handles['techHandle'];
        $domainRegistration->billingHandle  =   $handles['billingHandle'];
        $domainRegistration->nameServers    =   $nameServers;
        $domainRegistration->autorenew      =   'default';

        //use dns templates
        if($params['dnsTemplate'] && $params['dnsTemplate'] != 'None')
        {
            $domainRegistration->nsTemplateName =   $params['dnsTemplate'];
        }

        if (OpenProvider\APITools::checkIfNsIsDefault($nameServers)) {
            $domainRegistration->nsTemplateName = 'Default';
        }
        
        if($params['tld'] == 'de') 
        {
            $domainRegistration->useDomicile = 1;
        }
        // New feature.
//        if($params['tld'] == 'nu'){
//            $domainRegistration->additionalData->companyRegistrationNumber = $fields['socialSecurityNumber']; 
//            $domainRegistration->additionalData->passportNumber = $fields['companyRegistrationNumber']; 
//        }
        
        //Additional domain fileds
        if(!empty($params['additionalfields']))
        {
            $additionalData                 =   new \OpenProvider\AdditionalData();
            
            foreach($params['additionalfields'] as $name => $value)
            {
                $additionalData->set($name, $value);
            }
            
            $domainRegistration->additionalData =   $additionalData;
        }
        
        $idn = new \idna_convert();
        if(
                $params['sld'].'.'.$params['tld'] == $idn->encode($params['sld'].'.'.$params['tld']) 
                && strpos($params['sld'].'.'.$params['tld'], 'xn--') === false
            )
        {
            unset($domainRegistration->additionalData->idnScript);
        }
        
        sleep(5);
        $api = new \OpenProvider\API($params);
        $api->registerDomain($domainRegistration);
        
        // store handles in database
        $storeHandle = new \OpenProvider\Handles();
        $storeHandle->importToWHMCS($api, $domain, $params['domainid'], $useLocalHandle);
        
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    return $values;
}


/**
 * Get domain name servers
 * @param type $params
 * @return type
 */
function openprovider_GetNameservers($params) 
{
    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        $nameservers        =   $api->getNameservers($domain);
        $return             =   array();
        $i                  =   1;
        
        foreach($nameservers as $ns)
        {
            $return['ns'.$i]    =   $ns;
            $i++;
        }
        
        return $return;
    }
    catch (\Exception $e)
    {
        return array
        (
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Change domain name servers
 * @param type $params
 * @return string
 */
function openprovider_SaveNameservers($params)
{
    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        $nameServers        =   \OpenProvider\APITools::createNameserversArray($params);
        
        $api->saveNameservers($domain, $nameServers);
    }
    catch (\Exception $e)
    {
        return array(
            'error' => $e->getMessage(),
        );
    }
    
    return 'success';
}

/**
 * Get registrar lock
 * @param type $params
 * @return type
 */
function openprovider_GetRegistrarLock($params)
{
    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));

        $lockStatus         =   $api->getRegistrarLock($domain);
    }
    catch (\Exception $e)
    {
        //Nothing...
    }

    return $lockStatus ? 'locked' : 'unlocked';;
}


/**
 * Save registrar lock
 * @param type $params
 * @return type
 */
function openprovider_SaveRegistrarLock($params)
{
    $values = array();

    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        $lockStatus         =   $params["lockenabled"] == "locked" ? 1 : 0;

        $api->saveRegistrarLock($domain, $lockStatus);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    return $values;
}

/**
 * Get domain DNS
 * @param type $params
 * @return array
 */
function openprovider_GetDNS($params)
{
    $dnsRecordsArr = array();
    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        $dnsInfo            =   $api->getDNS($domain);

        if (is_null($dnsInfo))
        {
            return array();
        }

        $supportedDnsTypes  =   \OpenProvider\APIConfig::$supportedDnsTypes;
        $domainName         =   $domain->getFullName();
        foreach ($dnsInfo['records'] as $dnsRecord)
        {
            if (!in_array($dnsRecord['type'], $supportedDnsTypes))
            {
                continue;
            }

            $hostname = $dnsRecord['name'];
            if ($hostname == $domainName)
            {
                $hostname = '';
            }
            else
            {
                $pos = stripos($hostname, '.' . $domainName);
                if ($pos !== false)
                {
                    $hostname = substr($hostname, 0, $pos);
                }
            }
            $prio = is_numeric($dnsRecord['prio']) ? $dnsRecord['prio'] : '';
            $dnsRecordsArr[] = array(
                'hostname' => $hostname,
                'type' => $dnsRecord['type'],
                'address' => $dnsRecord['value'],
                'priority' => $prio
            );
        }
    }
    catch (\Exception $e)
    {
    }
    
    return $dnsRecordsArr;
}

/**
 * Save domain DNS records
 * @param type $params
 * @return string
 */
function openprovider_SaveDNS($params)
{
    $dnsRecordsArr = array();
    $values = array();
    foreach ($params['dnsrecords'] as $tmpDnsRecord)
    {
        if (!$tmpDnsRecord['hostname'] && !$tmpDnsRecord['address'])
        {
            continue;
        }
        
        $dnsRecord          =   new \OpenProvider\DNSrecord();
        $dnsRecord->type    =   $tmpDnsRecord['type'];
        $dnsRecord->name    =   $tmpDnsRecord['hostname'];
        $dnsRecord->value   =   $tmpDnsRecord['address'];
        $dnsRecord->ttl     =   \OpenProvider\APIConfig::$dnsRecordTtl;

        if ('MX' == $dnsRecord->type) // priority - required for MX records; ignored for all other record types
        {
            if (is_numeric($tmpDnsRecord['priority']))
            {
                $dnsRecord->prio    =   $tmpDnsRecord['priority'];
            }
            else
            {
                $dnsRecord->prio    =   \OpenProvider\APIConfig::$dnsRecordPriority;
            }
        }
        
        if (!$dnsRecord->value)
        {
            continue;
        }
        
        if (in_array($dnsRecord, $dnsRecordsArr))
        {
            continue;
        }

        $dnsRecordsArr[] = $dnsRecord;
    }

    $domain = new \OpenProvider\Domain();
    $domain->name = $params['sld'];
    $domain->extension = $params['tld'];

    try
    {
        $api = new \OpenProvider\API($params);
        if (count($dnsRecordsArr))
        {
            $api->saveDNS($domain, $dnsRecordsArr);
        }
        else
        {
            $api->deleteDNS($domain);
        }
        
        return "success";
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
	
    return $values;
}

//
function openprovider_RequestDelete($params)
{
    $values = array();

    try
    {
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        
        $api->requestDelete($domain);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    
    return $values;
}

/**
 * 
 * @param type $params
 * @return type
 */
function openprovider_TransferDomain($params)
{
    $values = array();

    try
    {
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));

        $nameServers = \OpenProvider\APITools::createNameserversArray($params);
        
        $createNewHandles = false;
        $useLocalHandle = isset($params['useLocalHanlde']) ? (bool)$params['useLocalHanlde'] : false;
        
        if ($useLocalHandle)
        {
            // read user's handles
            $userId = $params['userid'];
            
            $handles = \OpenProvider\APITools::readCustomerHandles($userId);
            
            if ($handles->ownerHandle && $handles->adminHandle && $handles->techHandle && $handles->billingHandle)
            {
                $ownerHandle    =   $handles->ownerHandle;
                $adminHandle    =   $handles->adminHandle;
                $techHandle     =   $handles->techHandle;
                $billingHandle  =   $handles->billingHandle;
            }
            else
            {
                $createNewHandles = true;
            }
        }
        
        if (!$useLocalHandle || $createNewHandles)
        {
            $ownerCustomer = new \OpenProvider\Customer($params);
            $ownerHandle = \OpenProvider\APITools::createCustomerHandle($params, $ownerCustomer);
            
            $adminCustomer = new \OpenProvider\Customer($params);
            $adminHandle = \OpenProvider\APITools::createCustomerHandle($params, $adminCustomer);
            
            $techCustomer = new \OpenProvider\Customer($params);
            $techHandle = \OpenProvider\APITools::createCustomerHandle($params, $techCustomer);
            
            $billingCustomer = new \OpenProvider\Customer($params);
            $billingHandle = \OpenProvider\APITools::createCustomerHandle($params, $billingCustomer);
        }

        $domainTransfer                 =   new \OpenProvider\DomainTransfer();
        $domainTransfer->domain         =   $domain;
        $domainTransfer->period         =   $params['regperiod'];
        $domainTransfer->nameServers    =   $nameServers;
        $domainTransfer->ownerHandle    =   $ownerHandle;
        $domainTransfer->adminHandle    =   $adminHandle;
        $domainTransfer->techHandle     =   $techHandle;
        $domainTransfer->billingHandle  =   $billingHandle;
        $domainTransfer->authCode       =   $params['transfersecret'];

        if (OpenProvider\APITools::checkIfNsIsDefault($nameServers)) {
            $domainTransfer->nsTemplateName = 'Default';
        }
        
        if($params['tld'] == 'de') 
        {
            $domainTransfer->useDomicile = 1;
        }
        
        $idn = new \idna_convert();
        if($params['sld'].'.'.$params['tld'] == $idn->encode($params['sld'].'.'.$params['tld']))
        {
            unset($domainTransfer->additionalData->idnScript);
        }
        
        $api = new \OpenProvider\API($params);
        $api->transferDomain($domainTransfer);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    return $values;
}

//
function openprovider_RenewDomain($params)
{
    try
    {
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));

        $period = $params['regperiod'];

        $api = new \OpenProvider\API($params);
        $api->renewDomain($domain, $period);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    return $values;
}


/**
 * Get domain contact details
 * @param type $params
 * @return type
 */
function openprovider_GetContactDetails($params)
{
    try
    {
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));

        $api                =   new \OpenProvider\API($params);
        $values             =   $api->getContactDetails($domain);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    
    return $values;
}

//
function openprovider_SaveContactDetails($params)
{
    try
    {
        $api                =   new \OpenProvider\API($params);
        $handles            =   array_flip(\OpenProvider\APIConfig::$handlesNames);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        
        $params['getFromContactDetails'] = true;
        $customers  =   array();
                
        foreach($params['contactdetails'] as $contactName => $contactValues)
        {
            $customers[$handles[$contactName]]    =   new \OpenProvider\Customer($params, $contactName);
        }

        $api->SaveContactDetails($domain, $customers, $params['domainid']);
        
        // store handles in database
        $storeHandle = new \OpenProvider\Handles();
        $useLocalHandle = isset($params['useLocalHanlde']) ? (bool)$params['useLocalHanlde'] : false;
        $storeHandle->updateInWHMCS($api, $domain, $params['domainid'], $useLocalHandle);
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    return $values;
}

/**
 * Get domain epp code
 * @param type $params
 * @return type
 */
function openprovider_GetEPPCode($params)
{
    $values = array();

    try
    {
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));

        $api = new \OpenProvider\API($params);
        $eppCode = $api->getEPPCode($domain);
        
        if(!$eppCode)
        {
            throw new Exception('EPP code is not set');
        }
        $values["eppcode"] = $eppCode ? $eppCode : '';
    }
    catch (\Exception $e)
    {
        $values["error"] = $e->getMessage();
    }
    
    return $values;
}


/**
 * Add name server in domain
 * @param type $params
 * @return string
 */
function openprovider_RegisterNameserver($params)
{
            // get data from op
    $api                = new \OpenProvider\API($params);
    $domain             =   new \OpenProvider\Domain(array(
        'name'          =>  $params['sld'],
        'extension'     =>  $params['tld']
    ));
           
    try
    {
        
        $nameServer         =   new \OpenProvider\DomainNameServer();
        $nameServer->name   =   $params['nameserver'];
        $nameServer->ip     =   $params['ipaddress'];
        
        if (($nameServer->name == '.' . $params['sld'] . '.' . $params['tld']) || !$nameServer->ip)
        {
            throw new Exception('You must enter all required fields');
        }

        $api = new \OpenProvider\API($params);
        $api->nameserverRequest('create', $nameServer);
        
        return 'success';
    }
    catch (\Exception $e)
    {
        return array
        (
            'error' => $e->getMessage(),
        );
    }
}


/**
 * Modify existing name servers
 * @param type $params
 * @return string
 */
function openprovider_ModifyNameserver($params)
{
    $newIp      =   $params['newipaddress'];
    $currentIp  =   $params['currentipaddress'];
    
    // check if not empty
    if (($params['nameserver'] == '.' . $params['sld'] . '.' . $params['tld']) || !$newIp || !$currentIp)
    {
        return array(
            'error' => 'You must enter all required fields',
        );
    }
    
    // check if the addresses are different
    if ($newIp == $currentIp)
    {
        return array
        (
            'error' => 'The Current IP Address is the same as the New IP Address',
        );
    }
    
    try
    {
        $nameServer = new \OpenProvider\DomainNameServer();
        $nameServer->name = $params['nameserver'];
        $nameServer->ip = $newIp;

        $api = new \OpenProvider\API($params);
        $api->nameserverRequest('modify', $nameServer, $currentIp);
    }
    catch (\Exception $e)
    {
        return array
        (
            'error' => $e->getMessage(),
        );
    }
    
    return 'success';
}

/**
 * Delete name server from domain
 * @param type $params
 * @return string
 */
function openprovider_DeleteNameserver($params)
{
    try
    {
        $nameServer             =   new \OpenProvider\DomainNameServer();
        $nameServer->name       =   $params['nameserver'];
        $nameServer->ip         =   $params['ipaddress'];
        
        // check if not empty
        if ($nameServer->name == '.' . $params['sld'] . '.' . $params['tld'])
        {
            return array
            (
                'error'     =>  'You must enter all required fields',
            );
        }

        $api = new \OpenProvider\API($params);
        $api->nameserverRequest('delete', $nameServer);
        
        return 'success';
    }
    catch (\Exception $e)
    {
        return array
        (
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Synchronize domain status amd expiry date
 * @param type $params
 * @return type
 */
function openprovider_TransferSync($params)
{
    try
    {
        // get data from op
        $api                = new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        
        $opInfo             =   $api->retrieveDomainRequest($domain);
        
        if($opInfo['status'] == 'ACT')
        {
            return array
            (
                'completed'     =>  true,
                'expirydate'    =>  date('Y-m-d', strtotime($opInfo['renewalDate'])),
            );
        }
        
        return array();
    }
    catch (\Exception $ex)
    {
        return array
        (
            'error' =>  $ex->getMessage()
        );
    }

    return $values;
}

/**
 * Synchronize expiry date
 * @param type $params
 * @return type
 */
function openprovider_Sync($params)
{

    try
    {  
        $api                =   new \OpenProvider\API($params);
        $domain             =   new \OpenProvider\Domain(array(
            'name'          =>  $params['sld'],
            'extension'     =>  $params['tld']
        ));
        
        $opInfo             =   $api->retrieveDomainRequest($domain);
        
        $timestamp = strtotime($opInfo['renewalDate']);
        if($timestamp === false){
            logActivity('OpenProvider: Empty renewal date for domain: '.$params['sld'].'.'.$params['tld']);
            return array('error' => 'OpenProvider: Empty renewal date for domain: '.$params['sld'].'.'.$params['tld']);
        }

        $expirationDate      =   date('Y-m-d', $timestamp);
        
        if($timestamp < time())
        {
            return array
            (
                'expirydate'    =>  $expirationDate,
                'expired'       =>  true
            );            
        }
        else
        {
            return array
            (
                'expirydate'    =>  $expirationDate,
                'active'        =>  true
            );
        }
    }
    catch (\Exception $ex)
    {
        return array
        (
            'error' =>  $ex->getMessage()
        );
    }
}


