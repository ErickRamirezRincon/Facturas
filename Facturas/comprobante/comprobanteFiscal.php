<?php
header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

class ComprobanteFiscal
{
	public static function generarComprobantePDF($idFactura, $facturaTimbrada, $logo = '', $colorConfiguracion)
	{
		$simpleXml = simplexml_load_string(base64_decode($facturaTimbrada));
		$ns = $simpleXml->getNamespaces(true);
	    $simpleXml->registerXPathNamespace('cfdi',$ns['cfdi']);
	    $simpleXml->registerXPathNamespace('tfd',$ns['tfd']);


		$comprobante = $simpleXml->xpath('//cfdi:Comprobante');
		$version = (string) isset($comprobante[0]->attributes()->version) ? $comprobante[0]->attributes()->version : $comprobante[0]->attributes()->Version;

		if ($version == '3.2') {
			require_once(dirname(dirname(__FILE__)).'/generaPDF3.2.php');
			return generaPDF($facturaTimbrada, $logo, $colorConfiguracion, $idFactura);
		}else {
			require_once(dirname(dirname(__FILE__)).'/generaPDF.php');
			return generaPDF($facturaTimbrada, $logo, $colorConfiguracion, $idFactura);
		}
	}

	public static function comprimir($filezipname, array $files = array(), array $strings = array())
	{
		$zipArchive = new ZipArchive();
		if($zipArchive->open($filezipname, ZipArchive::CREATE) === true){
			foreach($files as $item)
				$zipArchive->addFile($item['filename'], $item['localname']);

			foreach($strings as $item)
				$zipArchive->addFromString($item['localname'], $item['content']);

			$zipArchive->close();	
			return realpath($filezipname);
		}
		throw new Exception("173 - No se pudó crear el ZIP: ".$filezipname);
	}

	public static function obtenerComprobante($param, $content)
	{
		$elementos 	= explode(".", $param);
		$urlArchivo = dirname(__FILE__);
        $urlArchivo .= ($elementos[1] == 'xml') ? "/tmp/xml/" : "/tmp/pdf/";

        $archivo = $urlArchivo.uniqid("ws_").".zip";

		$urlComprimido = self::comprimir($archivo, [], [["localname" => $param, 'content' => base64_decode($content)]]);
		$handler = fopen($urlComprimido, "r");
		$cadena = stream_get_contents($handler);
		fclose($handler);
		
        unlink($archivo);
		return base64_encode($cadena);
	}

	public static function depuraXML($text)
	{
		$text = preg_replace("/&amp;|&/", "&amp;", $text);
		$patterns = ['\'', '´', '>', '<'];
		$entities = ['&quot;', '&apos;', '&gt;', '&lt;'];
		$entitiesArray = [];
		$patternsArray = [];
		foreach ($entities as $key => $val) 
			array_push($entitiesArray, "$1=\"$2".$val."$4\"");
		foreach ($patterns as $key => $val) 
			array_push($patternsArray, "/(\S+)=\"([^\"]*)(".$val.")([^\"]*)\"/");
		return preg_replace($patternsArray, $entitiesArray, $text);
	}

	public static function depuraValoresXML($text)
	{
		$patterns = ['/&amp;|&/', '/\'/', '/´/', '/>/', '/</'];
		$entities = ['&amp;', '&quot;', '&apos;', '&gt;', '&lt;'];		
		return preg_replace($patterns, $entities, $text);
	}
}

?>