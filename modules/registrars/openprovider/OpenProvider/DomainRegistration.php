<?php
namespace OpenProvider;

class DomainRegistration extends \OpenProvider\AutoloadConstructor
{
    /**
     *
     * @var \OpenProvider\Domain 
     */
    public $domain          =   null;
    
    /**
     *
     * @var int 
     */
    public $period          =   null;
    
    /**
     *
     * @var string
     */
    public $ownerHandle     =   null;
    
    /**
     *
     * @var string
     */
    public $adminHandle     =   null;
    
    /**
     *
     * @var string
     */
    public $techHandle      =   null;
    
    /**
     *
     * @var string
     */
    public $billingHandle   =   null;
    
    
    public $nameServers     =   null;
    
    /**
     *
     * @var string
     */
    public $autorenew       =   'off';
    
    /**
     *
     * @var \OpenProvider\AdditionalData
     */
    public $additionalData  =   null;
    
    /**
     *
     * @var string
     */
    public $nsTemplateName  =   null;
    
    /**
     *
     * @var int (0|1) default 0
     */
    public $useDomicile = 0;
    
}
