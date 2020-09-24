<?php 
$timeStart = microtime(true);

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php');

require_once('Factura.php');

date_default_timezone_set("America/Mexico_City");
error_reporting(E_ALL);
ini_set("display_errors", "On");

Logger::configure("log4php.properties");
$logger = Logger::getLogger(basename(__FILE__));
//$logger->info("INICIANDO SCRIPT");

function shutdownHandler($timeStart){
	$logger = Logger::getLogger(basename(__FILE__));
	$timeEnd = microtime(true);
    $time = $timeEnd - $timeStart;
    /*$logger->info("   INICIO: ".$timeStart);        
    $logger->info("      FIN: ".$timeEnd);        
    $logger->info("   TIEMPO: ".$time);        
	$logger->info("FINALIZANDO SCRIPT");*/
}

register_shutdown_function('shutdownHandler', $timeStart);

try{
	$soapServer = new SoapServer("FacEWS.wsdl", [
		'trace' => true, 
		'exceptions' => 1,
		'cache_wsdl' => WSDL_CACHE_NONE
	]);
	$soapServer->setClass("Factura", $soapServer);
	ob_clean();
	$soapServer->handle();
}catch(SoapFault $exception){
	$logger->error($exception->getMessage());
}

?>
