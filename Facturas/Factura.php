<?php
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php';
require_once 'comprobante/comprobanteFiscal.php';
require_once 'validaObjeto.php';
require_once 'ObjetoFactura.php';
require_once 'PAC.php';
require_once 'BD/autoloader.php';
require_once 'RequestEmisionMarti.php';
require_once 'PoFactura.php';
require_once 'MailService.php';
require_once 'MailDao.php';
require_once 'ClientMartiWS.php';
require_once 'verificarNoReferencia.php';

class Factura
{
	const USUARIO  = "MARTI";
	const PASSWORD = "020920151435";
	CONST SUCURSALES_CANCUN = "4132,4150,4163,4174,4181,4266,4270,4297,4437,4537,4540,4556,4590,4544";
	const URL_LOGO_CLUB = "http://200.53.151.77/clubes/face/com/bimg/";

	private $logger;
	private $loggerConfidential;
	private $noTicket;
	private $ticketObject = array();

	private $tipoComprobante = array();

	public function __construct($origen = 0)
	{
		if(!$origen instanceof SoapServer){
			if($origen == 3){
				Logger::configure(__DIR__.DIRECTORY_SEPARATOR."log4php.properties");
			}
		}
		$this->logger 				= Logger::getLogger(basename(__FILE__));
		$this->loggerConfidential 	= Logger::getLogger("CONFIDENTIAL");

		$this->tipoComprobante = array('P','T');
	}

	private function validaReferencia($idempresa, $referencia){
		$funcion = ($idempresa == 3 || $idempresa == 4) ? "validarNumeroReferenciaClubes" : "validarNumeroReferencia";
		return $funcion($referencia);
	}

	public function eliminarDir($directorio)
	{
		//$this->logger->info("Eliminando el directorio $directorio");
		foreach(glob($directorio . "/*") as $archivosCarpeta){
			if (is_dir($archivosCarpeta))
				$this->eliminarDir($archivosCarpeta);
			else
				unlink($archivosCarpeta);
		}
		rmdir($directorio);
	}

	private function descomprimirZip($archivoComprimido, $directorio)
	{
		//$this->logger->info("Descomprimir archivo ZIP.");
		$name = pathinfo($archivoComprimido, PATHINFO_FILENAME );
		$zipArchive = new ZipArchive();

		if($zipArchive->open($archivoComprimido) === true){
			$zipArchive->extractTo($directorio.$name);
			$zipArchive->close();
			return realpath($directorio.$name);
		}else{
			$this->logger->error("El archivo no se pudo abrir, puede que no sea un ZIP o este dañado, favor de verificarlo.");
			throw new Exception("151 - El archivo no se pudo abrir, puede que no sea un ZIP o este dañado, favor de verificarlo");
		}
	}

	private function obtenerXML($param)
	{
		//$this->logger->info("Obtener XML del ticket tienda.");		

		$archivoZip = base64_decode($param);
		$archivo 	= 'comprobante/tmp/xml/tmp_'.uniqid().'.zip';

		if (!file_exists('comprobante/tmp'))
			mkdir('comprobante/tmp',0777);

		file_put_contents($archivo, $archivoZip);		
		$dirDescomprimido = $this->descomprimirZip($archivo, 'comprobante/tmp/xml/');		

		$listFiles 	= array();
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirDescomprimido)) as $recursiveIteratorIterator){
			if(!$recursiveIteratorIterator->isDir()){
				if($recursiveIteratorIterator->getExtension() == "xml")
					array_push($listFiles, $recursiveIteratorIterator);
			}
		}
		
		if(empty($listFiles)){
			unlink($archivo);
			$this->eliminarDir($dirDescomprimido);
			throw new Exception("153 - No existe un archivo XML dentro del ZIP.");
		}

		if(count($listFiles) > 1){
			unlink($archivo);
			$this->eliminarDir($dirDescomprimido);
			throw new Exception("154 - Existe mas de un archivo XML dentro del ZIP.");
		}

		$contenidoXML = file_get_contents($listFiles[0]->getPathName());
		$array = ["xml" => $contenidoXML, "archivo" => $archivo];

		$response = json_encode($array);		
		if(json_last_error() != JSON_ERROR_NONE){
			$array["xml"] = utf8_encode($array["xml"]);
			$response = json_encode($array);
		}

		unlink($archivo);
		$this->eliminarDir($dirDescomprimido);
		return $response;
	}

	private function generaAddenda($addenda)
	{
		$valueXML = function($text){
			return ComprobanteFiscal::depuraValoresXML($text);
		};
		
		$addenda = json_decode($addenda);		
		$addendaText = "<TMARTI>\n\t<GENERALES>\n\t\t<TIPO_DOCUMENTO>".$valueXML($addenda->TMARTI->GENERALES->TIPO_DOCUMENTO)."</TIPO_DOCUMENTO>\n\t\t<SUCURSAL>";
		$addendaText .=	"\n\t\t\t<CODIGO_SUCURSAL>".$valueXML($addenda->TMARTI->GENERALES->SUCURSAL->CODIGO_SUCURSAL)."</CODIGO_SUCURSAL>";
		$addendaText .=	"\n\t\t\t<NOMBRE_SUCURSAL>".$valueXML($addenda->TMARTI->GENERALES->SUCURSAL->NOMBRE_SUCURSAL)."</NOMBRE_SUCURSAL>";
		$addendaText .=	"\n\t\t</SUCURSAL>\n\t\t<TICKET>\n\t\t\t<NUMERO_TICKET>".$valueXML($addenda->TMARTI->GENERALES->TICKET->NUMERO_TICKET)."</NUMERO_TICKET>";
		$addendaText .=	"\n\t\t</TICKET>";
		$addendaText .=	"\n\t\t<CAJA>\n\t\t\t<NUMERO_CAJA>".$valueXML($addenda->TMARTI->GENERALES->CAJA->NUMERO_CAJA)."</NUMERO_CAJA>";
		$addendaText .=	"\n\t\t\t<NOMBRE_CAJERO>".$valueXML($addenda->TMARTI->GENERALES->CAJA->NOMBRE_CAJERO)."</NOMBRE_CAJERO>\n\t\t</CAJA>\n\t\t<METODOS_PAGO>";

		$metodosPago = '';
		$contadorMetodosPago = 1;
		foreach ($addenda->TMARTI->GENERALES->METODOS_PAGO->METODO_PAGO as $val) {
			$metodosPago .= "\n\t\t\t";
			//$metodosPago .= "<METODO_PAGO Id='".$contadorMetodosPago."'>";
			$metodosPago .= '<METODO_PAGO Id="'.$contadorMetodosPago.'">';
			$metodosPago .= "\n\t\t\t\t<DESCRIPCION>".$valueXML($val->DESCRIPCION)."</DESCRIPCION>";
			$metodosPago .= "\n\t\t\t</METODO_PAGO>";
            $contadorMetodosPago++;
		}
		$metodosPago .= "\n\t\t</METODOS_PAGO>";

		$referenciaNotaCredito = "";
		if(!empty($addenda->TMARTI->GENERALES->REFERENCIA_NOTA_CREDITO)){
			$referenciaNC = $addenda->TMARTI->GENERALES->REFERENCIA_NOTA_CREDITO;
			$referenciaNotaCredito.= "\n\t\t\t<REFERENCIA_NOTA_CREDITO>";
			$referenciaNotaCredito.= "\n\t\t\t\t<SERIE>".$valueXML($referenciaNC->serie)."</SERIE>";
			$referenciaNotaCredito.= "\n\t\t\t\t<FOLIO>".$valueXML($referenciaNC->folio)."</FOLIO>";
			$referenciaNotaCredito.= "\n\t\t\t\t<FECHA>".$valueXML($referenciaNC->fecha)."</FECHA>";
			$referenciaNotaCredito.= "\n\t\t\t</REFERENCIA_NOTA_CREDITO>";
		}

		$descuentos = "\n\t\t<DESCUENTOSGLOBALES>\n";
		foreach ($addenda->TMARTI->GENERALES->DESCUENTOSGLOBALES->DESCUENTO as $val) {
			$descuentos .= "\t\t\t";
			$descuentos .= '<DESCUENTO DESCRIPCION="'.$val->DESCRIPCION.'" IMPORTE="'.$val->IMPORTE.'"/>';
			$descuentos .= "\n";
		}

		$descuentos .= "\t\t</DESCUENTOSGLOBALES>\n\t</GENERALES>";
		$partidas = "\n\t<CONCEPTOS>\n";

		foreach ($addenda->TMARTI->CONCEPTOS->CONCEPTO as $key => $partida){
			$partidas .= "\t\t";
			$partidas .= "<CONCEPTO Id='".($key+1)."'>";
            $partidas .= "\n\t\t\t<CANTIDAD>".$valueXML($partida->CANTIDAD)."</CANTIDAD>";
            $partidas .= "\n\t\t\t<UNIDAD>".$valueXML($partida->UNIDAD)."</UNIDAD>";

            if(isset($partida->NIDENTIFICACION))
            	$partidas .= "\n\t\t\t<NIDENTIFICACION>".$valueXML($partida->NIDENTIFICACION)."</NIDENTIFICACION>";

            $partidas .= "\n\t\t\t<DESCRIPCION>".$valueXML($partida->DESCRIPCION)."</DESCRIPCION>";
            $partidas .= "\n\t\t\t<VALORUNITARIO>".$valueXML($partida->VALORUNITARIO)."</VALORUNITARIO>";
            $partidas .= "\n\t\t\t<IMPORTE>".$valueXML($partida->IMPORTE)."</IMPORTE>";

            if(isset($partida->DESCUENTOS)){
	            foreach ($partida->DESCUENTOS as $key) {
	            	$partidas .= "\n\t\t\t<DESCUENTOS>";
	            	$partidas .= "\n\t\t\t\t<IMPORTE>".$valueXML($key->IMPORTE)."</IMPORTE>";
	            	$partidas .= "\n\t\t\t\t<TASA>".$valueXML($key->TASA)."</TASA>";
	            	$partidas .= "\n\t\t\t</DESCUENTOS>";
	            }
	        }

            $partidas .= "\n\t\t</CONCEPTO>\n";
		}

		$partidas .= "\t</CONCEPTOS>";
		
		$texto = "\n\t<TEXTOS>";
		$texto .= "\n\t\t<IMPORTE_LETRA>".$valueXML($addenda->TMARTI->TEXTOS->IMPORTE_LETRA)."</IMPORTE_LETRA>\n";
		$texto .= "\n\t\t<COMENTARIOS>".$valueXML($addenda->TMARTI->GENERALES->textoPie)."</COMENTARIOS>\n";
		$texto .= "\t</TEXTOS>";

		$datosAdicionales = '';
		$datosUsuario = '';
		
		if(isset($addenda->TMARTI->DATOS_ADICIONALES_CLUBES)){
			$datosAdicionales = "\n\t<DATOS_ADICIONALES_CLUBES>";

			foreach ($addenda->TMARTI->DATOS_ADICIONALES_CLUBES as $key){
				$datosAdicionales .= "\n\t\t<DATO_ADICIONAL>";
				$datosAdicionales .= "\n\t\t\t<DESCRIPCION>".$valueXML($key->DESCRIPCION)."</DESCRIPCION>";
				$datosAdicionales .= "\n\t\t\t<VALOR>".$valueXML($key->VALOR)."</VALOR>";
				$datosAdicionales .= "\n\t\t</DATO_ADICIONAL>";
			}
			
			$datosAdicionales .= "\n\t</DATOS_ADICIONALES_CLUBES>";
		}

		$addendaText .= $metodosPago.$referenciaNotaCredito.$descuentos.$partidas.$texto.$datosAdicionales.$datosUsuario."\n</TMARTI>";		
		libxml_use_internal_errors(true);
		libxml_clear_errors();
		/*while(!simplexml_load_string($addendaText)){
			$this->logger->error(libxml_get_errors());
			libxml_clear_errors();
			$addendaText = ComprobanteFiscal::depuraXml($addendaText);
		}*/
		return $addendaText;
	}

	public function Emitir($factura, $detalle, $direccionesFactura=[])
	{


		$detalle = json_decode($detalle);
		
		$numReferenciaTxt = "No. de referencia: ".$detalle->numeroReferenciaFacturacion;
		// $this->loggerConfidential->debug("Emitir ".$numReferenciaTxt);
		

		$response = ["status" => 1, "mensaje" => "OK"];
		$info = (new validaObjeto($factura))->validaInfoRequerida();

		if(!empty($info))
			throw new Exception($info);
		
		try{
			if(empty($detalle->logo)){
				$parametroService = new tConfiguracionSistema();
        		$parametroService->consultar(['empresa' => $detalle->empresa]);
	        	$detalle->logo 	= $parametroService->obtenParametro("LOGO");
        		$detalle->color = $parametroService->obtenParametro("COLOR_BACKGROUND");				
			}

		

			$resultCertificados = json_decode((new cCertificado())->consultarCertificado([
				"id_cempresa" 	=> $detalle->empresa, 
				"activo"		=> 1
			]));

			if(empty($resultCertificados->noCertificado))
				throw new Exception("155 - No existen certificados resgitrados para la empresa: ".$factura->emisor->nombre.", favor de verificar.");

			$detalle->origen = (empty($detalle->origen)) ? '3': $detalle->origen;
			

			//Duplica el JSON original
			
			// $objetoFactura 	= new ObjetoFactura($factura);
			// $objeto 		= $objetoFactura->generaObjetoFactura();
			// //$this->getLogger('ObjetoFactura: ',$objeto);
			// $objeto = $factura;
			// //error_log('JSON: '.gettype($factura));
			// $jsonFactura 		= json_decode($factura,true);
			// //error_log('JsonFactura: '.print_r($jsonFactura,1));
			// $jsonFactura->noCertificado = $resultCertificados->noCertificado;
			// error_log("jsonfactura: ".print_r($factura,1));

			$responseTimbrado = PAC::timbrar($factura, $detalle);
			// $hashFactura = $responseTimbrado->Uuid.'|'.$responseTimbrado->FechaTimbrado.'|'.$direccionesFactura['serie'].'|'.$direccionesFactura['folio'];
			$hashFactura = $responseTimbrado->Uuid.'|'.$factura->ComprobanteJson->Emisor->Rfc;
			error_log('HashOrig: '.$hashFactura);
			$hashFactura = md5($hashFactura);
			error_log('HashOrig: '.$hashFactura);
			$this->checkAddress('cempresa',$hashFactura, $direccionesFactura['cempresa']['dato'], $direccionesFactura['cempresa']['direccion']);
			$this->checkAddress('csucursal',$hashFactura, $direccionesFactura['sucursal']['dato'], $direccionesFactura['sucursal']['direccion']);
			
			if (!isset($factura->Version))
				$id = $factura->comprobante->serie."_".sprintf("%05s", $responseTimbrado->folio)."_".$responseTimbrado->uuid;
			else
				$id = $factura->ComprobanteJson->Serie."_".sprintf("%05s", $responseTimbrado->folio)."_".$responseTimbrado->Uuid;

			$response['pdf'] = "";
			$response['cfdiTimbrado'] = '';

			if (!isset($factura->Version) || $factura->ComprobanteJson->TipoDeComprobante != 'P') {
				if($detalle->pdf) {
					if (!isset($factura->Version)) {
						$pdf = ComprobanteFiscal::generarComprobantePDF($id, $responseTimbrado->facturaTimbradaBase64, $detalle->logo, $detalle->colorConfiguracion);
						$response['pdf'] = ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdf));
					}else {
						$pdf = ComprobanteFiscal::generarComprobantePDF($id, $responseTimbrado->DocumentoTimbradoBase64, $detalle->logo, $detalle->colorConfiguracion);
						$response['pdf'] = ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdf));
					}
				}
			}

			// if ($detalle->xml) {
			// 	if (!isset($factura->Version)) {
			// 		$pdf = ComprobanteFiscal::generarComprobantePDF($id, $responseTimbrado->facturaTimbradaBase64, $detalle->logo, $detalle->colorConfiguracion);
			// 		$response['pdf'] = ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdf));
			// 	}else {
			// 		$pdf = ComprobanteFiscal::generarComprobantePDF($id, $responseTimbrado->DocumentoTimbradoBase64, $detalle->logo, $detalle->colorConfiguracion);
			// 		$response['pdf'] = ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdf));
			// 	}
			// }
			error_log("response cfdi registrar marti xml".print_r($responseTimbrado,1));
			// error_log("response cfdi registrar marti xml".print_r($detalle,1));

			if ($detalle->xml) {
				if (!isset($factura->Version)) {
					$response['cfdiTimbrado'] = ComprobanteFiscal::obtenerComprobante($id.".xml", $responseTimbrado->facturaTimbradaBase64);
				}else {
					error_log("response cfdi registrar marti".print_r($responseTimbrado,1));
					$response['cfdiTimbrado'] = ComprobanteFiscal::obtenerComprobante($id.".xml", $responseTimbrado->DocumentoTimbradoBase64);
				}
			}

			/*if($detalle->pdf){
				$pdf = ComprobanteFiscal::generarComprobantePDF($id, $responseTimbrado->facturaTimbradaBase64, $detalle->logo, $detalle->colorConfiguracion);
				$response['pdf'] = ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdf));
			}else
				$response['pdf'] = "";
*/
			if (!isset($factura->Version)) {
				if($detalle->origen == 2){
					$this->registrarMarti([
						"tipoDocumento" => $factura->comprobante->tipoDeComprobante,
						"folio"			=> $responseTimbrado->folio,
						"total"			=> $factura->comprobante->total,
						"iva"			=> $factura->impuestos->totalImpuestosTrasladados,
						"fechaTimbrado"	=> $responseTimbrado->fechaTimbrado,
						"rfcReceptor"	=> $factura->receptor->rfc,
						"uuid"			=> $responseTimbrado->uuid,
						"serie"			=> $factura->comprobante->serie,
						"referencia"	=> $detalle->numeroReferenciaFacturacion,
						"cfdiTimbrado"	=> $responseTimbrado->DocumentoTimbradoBase64,
						"idEmpresa"		=> $detalle->empresa
					]);
			
				}
			}

			else if ($factura->ComprobanteJson->TipoDeComprobante != 'P') { //Por el momento no se envia a registar los complementos de pago
				
				$this->getLogger('Guardamos en DB!',$factura->ComprobanteJson->TipoDeComprobante);

				$this->registrarMarti([
						"tipoDocumento" => $factura->ComprobanteJson->TipoDeComprobante,
						"folio"			=> $responseTimbrado->folio,
						"total"			=> $factura->ComprobanteJson->Total,
						"iva"			=> $factura->ComprobanteJson->Impuestos->TotalImpuestosTrasladados,
						"fechaTimbrado"	=> $responseTimbrado->FechaTimbrado,
						"rfcReceptor"	=> $factura->ComprobanteJson->Receptor->Rfc,
						"uuid"			=> $responseTimbrado->Uuid,
						"serie"			=> $factura->ComprobanteJson->Serie,
						"referencia"	=> $detalle->numeroReferenciaFacturacion,
						"cfdiTimbrado"	=> $response['cfdiTimbrado'],
						"idEmpresa"		=> $detalle->empresa
					]);	

				
				
			
			}


			if(!$detalle->xml)
				$response['cfdiTimbrado'] = "";

			$this->getLogger('ResponseTimbrado2: ',$responseTimbrado);
			if (!isset($factura->Version)) {
				$response['id'] 			= $id;
				$response['uuid'] 			= $responseTimbrado->uuid;
				$response['folio'] 			= $responseTimbrado->folio;
				$response['fechaTimbrado'] 	= $responseTimbrado->fechaTimbrado;
				$response['xml']			= $responseTimbrado->facturaTimbradaBase64;
			}else {
				$this->getLogger('ResponseTimbradoUUID: ',$responseTimbrado->Uuid);
				$response['id'] 			= $id;
				$response['uuid'] 			= $responseTimbrado->Uuid;
				$response['folio'] 			= $responseTimbrado->folio;
				$response['fechaTimbrado'] 	= $responseTimbrado->FechaTimbrado;
				$response['xml']			= $responseTimbrado->DocumentoTimbradoBase64;
			}
			
		}catch(Exception $e){
			throw new Exception($e->getMessage());
		}
		return $response;
	}

	private function registrarMarti(array $data)
	{
		error_log("datos registrar Marti: ".print_r($data,1));
		// file_put_contents("texto.txt",$data);
		if(strtoupper($data["tipoDocumento"]) == "I") {
			$data["tipoDocumento"] = ClientMartiWS::FACTURA;
		}else{
		 	$data["tipoDocumento"] = ClientMartiWS::NOTA_CREDITO; 
		 	if($data["idEmpresa"] != 3 || $data["idEmpresa"] != 4)
		 		$data["referencia"] = substr($data["referencia"], 10, 4);
		 	else
				$data["referencia"] = substr($data["referencia"], 6, 4);
		}

		(new ClientMartiWS())->registrarMarti(
			new PoFactura(
				$data["tipoDocumento"], 
				$data["folio"],
            	$data["total"], 
            	$data["iva"],
            	ClientMartiWS::WS_MARTI_USER, 
            	ClientMartiWS::WS_MARTI_PASS, 
            	$data["fechaTimbrado"], 
            	$data["rfcReceptor"], 
            	$data["uuid"],
            	$data["serie"], 
            	$data["referencia"], 
            	$data["cfdiTimbrado"]
            ), 
            $data["idEmpresa"]
        );
	}

	private function validarSucursalEmpresa($sucursal, $empresa, $referencia)
	{
		$this->logger->info("No. referencia: ".$referencia." - Validando si la sucursal ".$sucursal." pertenece a la empresa ".$empresa);

		//error_log(print_r($sucursal, true));
		$listSucursal = (new cSucursal())->consultar([
			'sucursal' => $sucursal,
			"empresa" => $empresa,
			"activo" => 1
		]);

		if(empty($listSucursal))
			return false;
		return $listSucursal[0];
	}

	private function validarSerieSucursal($serie, $sucursal, $referencia)
	{
		$this->logger->info("No. referencia: ".$referencia." - Validando si la serie ".$serie." pertenece a la sucursal ".$sucursal);
		$listSerie = (new cSerie())->consultar([
			'serie' => $serie, 
			"sucursal" => $sucursal,
			"activo" => 1
		]);	

		if(empty($listSerie))
			return false;
		return $listSerie[0];
	}

	private function validarFechaDiasGracia($fechaTicket, $diasParametro = 30)
	{
		$fechaTicket 		= strtotime($fechaTicket);
        $fechaHoy 			= strtotime(date("Y-m-d H:i:s"));
        $fechaHoyLimite 	= strtotime("-$diasParametro day", $fechaHoy);
        $this->getLogger('Fecha1: '.date('Y-m-d H:i:s',$fechaTicket).' Fecha2:'.date('Y-m-d H:i:s',$fechaHoyLimite));
        return ($fechaHoyLimite > $fechaTicket);
	}


	private function enviarEmail($params){
		require_once dirname(dirname(__DIR__))."/class/config.php";

		$params 				= json_decode($params);
		$attachment 			= array("comprobante/tmp/xml/".$params->id.".xml",
										"comprobante/tmp/pdf/".$params->id.".pdf");
		$params->valReemplazo 	= json_decode(json_encode($params->valReemplazo), true);

		$tipoClub = $this->obtenerConfiguracionClub($params->codSuc, $params->empresa);

		$user 					= $params->cliente;
		$mailService 			= (new MailService())->getInstance();

		for($i = 1; $i <= count($params->valReemplazo); $i++){
			$valorBuscar 			= "%s".$i;
			$valorReemplazar 		= $params->valReemplazo['A'.$i];
			$valorReemplazar 		= (strpos($valorReemplazar, ".00")) ? number_format($valorReemplazar, 2, '.', ',') : $valorReemplazar;
			//error_log("Buscar: ".$valorBuscar." reemplazar por: ".$valorReemplazar);
			$params->cuerpoCorreo 	= str_replace($valorBuscar, $valorReemplazar, $params->cuerpoCorreo);
		}
		
		$club 					= str_replace(" ", "_", $tipoClub['clubDescripcion']);
		$params->cuerpoCorreo 	= str_replace("#logotipo", "<img src='".$config_logo_imagen_url.$club.".jpg' width='119' height='71' />" , $params->cuerpoCorreo);
		$params->tituloEmail 	= $params->tituloEmail.$tipoClub['clubDescripcion'];
		//$this->logger->info("Parámetros para envío de correo: ");
		//$this->logger->info($params);

		return $mailService->enviar($params->email, $params->tituloEmail, $params->cuerpoCorreo, 
				$params->cuerpoCorreo, $params->copiaEmail, $params->copiaOculta, 
				$params->from, $attachment);
	}

	public function EmitirCFDI($peticion) {
		$xml = $this->obtenerXML($peticion->ticketTienda);
		$xml = json_decode($xml,true);
		$sxml = simplexml_load_string($xml['xml']);

		$ticketTienda = $sxml->xpath('//ticketTienda');

		$tipoComprobante = (string)$ticketTienda[0]->attributes()->tipoDeComprobante;
		if ($tipoComprobante != 'P')
			return $this->EmitirCFDIFacturas($peticion);
		else {
			$peticion->xml = $xml;
			$peticion->sxml = $sxml;
			return $this->EmitirCFDIComplementos($peticion);
		}
	}

	public function EmitirCFDIFacturas($peticion) {
		$response = [
			"respuesta" => [
				"estatus" => 1, 
				"descripcion" => "OK",
				"numeroReferenciaFacturacion" => $peticion->numeroReferenciaFacturacion
			]
		];

		$origen = 1;
		if(strpos($peticion->usuario, "_")){
			$origen = explode("_", $peticion->usuario);
			$peticion->usuario = $origen[0];
			$origen = $origen[1];
		}

		try{
			$numReferenciaTxt = "No. referencia: ".$peticion->numeroReferenciaFacturacion." - ";

			$xml = json_decode($this->obtenerXML($peticion->ticketTienda));
			$this->logger->info("No. de referencia: ".$peticion->numeroReferenciaFacturacion);
			$this->loggerConfidential->debug($numReferenciaTxt."Ticket tienda: ".$xml->xml);

			libxml_use_internal_errors(true);
			libxml_clear_errors();
			while(!$simpleXml = simplexml_load_string($xml->xml)){
				$this->logger->error(libxml_get_errors());
				libxml_clear_errors();
				$xml->xml = ComprobanteFiscal::depuraXml($xml->xml);
			}

			$ticket 		 = $simpleXml->xpath('//ticketTienda');

			$numTicket = (string)$ticket[0]->attributes()->NumTicket;
			if (strtoupper(substr($numTicket, 0, 1)) == 'G') {
				$formaPago = (string)$ticket[0]->attributes()->formaDePago;

				if ($formaPago == '99') {
					throw new Exception("245 - Para facturas globales la forma de pago no puede ser 'por definir' (99).");
				}
			}

			$serie 			 = (string)$ticket[0]["serie"];
			$fecha 			 = (string)$ticket[0]["fecha"];
			$fechaTicket 	 = str_replace("/", "-", (string)$ticket[0]["FechaTicket"]);
			$horaTicket 	 = (string)$ticket[0]["HoraTicket"];
			$fechaHoraTicket = date('Y-m-d', strtotime($fechaTicket))." ".$horaTicket;
			$numeroTicket 	 = (string)$ticket[0]["NumTicket"];
			$backNumTicket   = $numeroTicket;
			$codigoSucursal  = (string)$ticket[0]["CodSuc"];
			$emisor 		 = $simpleXml->xpath('//Emisor');
			
			$tipoDocumento 	 = (string)$ticket[0]["tipoDeComprobante"];

			$rfcEmisor 		 = (string)$emisor[0]["rfc"];
			$nombreEmisor 	 = (string)$emisor[0]["nombre"];
			
			$resultEmpresas  = json_decode((new cEmpresa())->consultarEmpresa([
				'activo' => 1,				
				'rfc' => $rfcEmisor
			]));

			$parametroService 	= new tConfiguracionSistema();
        	$parametroService->consultar(['empresa' => $resultEmpresas->idEmpresa]);
        	$this->getLogger('ParametrosService: ',$parametroService->obtenParametro("COLOR_BACKGROUND"));
        	$this->getLogger('ID Empresa: ', $resultEmpresas->idEmpresa);    
	        $logo 				= $parametroService->obtenParametro("LOGO");
        	$color 				= $parametroService->obtenParametro("COLOR_BACKGROUND");
        	$diasGracia 		= $parametroService->obtenParametro("DIAS_LIMITE");
        	$from 				= $parametroService->obtenParametro("CEMAIL_FROM");
        	$subject 			= $parametroService->obtenParametro("CEMAIL_SUBJECT");
        	$plantillaEmail 	= $parametroService->obtenParametro("CEMAIL_CONTENT");
        	$copiaEmail 		= $parametroService->obtenParametro("CEMAIL_CC");
        	$copiaOculta 		= $parametroService->obtenParametro("CEMAIL_CO");
        	$copiaEmail 		= ($copiaEmail == 'undefined' || empty($copiaEmail)) ? '' : $copiaEmail;
			$copiaOculta 		= ($copiaOculta == 'undefined' || empty($copiaOculta)) ? '' : $copiaOculta;
        	$refFacturaGlobal 	= mb_substr($peticion->numeroReferenciaFacturacion, 0, 1);
	        
	        //Verificación si es tipo de comprobante P y si es 3.3
	        $tipoComprobante = (string)$ticket[0]["tipoDeComprobante"];
	        $versionCFDI = (string)$ticket[0]['version'];

	        $validarXml = true;

	        if ($versionCFDI == '3.3' && in_array($tipoComprobante, $this->tipoComprobante))
	        	$validarXml = false;

	        if ($validarXml) {
				if($rfcEmisor == "") 
					throw new Exception("109 - FALTA CAMPO DE EMISOR - RFC-VACIO. 110 - RFC DEL EMISOR INVALIDO DEBE SER 12 O 13 CARACTERES.");
				
				if($peticion->usuario != self::USUARIO || $peticion->password != self::PASSWORD)
					throw new Exception("156 - Usuario o Contraseña inválidos.");

				if( !empty($fechaTicket) || !empty($horaTicket) ){
					if( empty($fechaTicket) )
						throw new Exception("191 - El ticket ".$peticion->numeroReferenciaFacturacion." no cuenta con una fecha de compra válida, sin embargo cuenta con una hora de ticket ". $horaTicket);

					if( empty($horaTicket) )
						throw new Exception("192 - El ticket ".$peticion->numeroReferenciaFacturacion." no cuenta con una hora de compra válida, sin embargo cuenta con una fecha de ticket ". $fechaTicket);
					if($this->validarFechaDiasGracia($fechaHoraTicket, $diasGracia))
						throw new Exception("188 - El número de referencia ".$peticion->numeroReferenciaFacturacion." excede el límite de ".$diasGracia." días permitidos para la facturación por el ADMIN.");
				}

				if(empty($resultEmpresas->idEmpresa))
					throw new Exception("183 - La empresa '".$nombreEmisor."' con RFC '".$rfcEmisor."' no se encuentra registrada, favor de verificar la información.");

				if(strlen($peticion->numeroReferenciaFacturacion) > 31)
					throw new Exception("181 - La longitud máxima permitida en el número de referencia es de 30 dígitos.");

				//if(!is_numeric($peticion->numeroReferenciaFacturacion))
					//throw new Exception("182 - Referencia inválida '".$peticion->numeroReferenciaFacturacion."', debe ser númerica.");

				//if (!$this->validaReferencia($result->idEmpresa, $factura->numeroReferenciaFacturacion))
					//throw new Exception("190 - Referencia inválida '".$peticion->numeroReferenciaFacturacion."'.");

				if(!$sucursal = $this->validarSucursalEmpresa($codigoSucursal, $resultEmpresas->idEmpresa, $peticion->numeroReferenciaFacturacion))
					throw new Exception("185 - La sucursal ".$codigoSucursal." no corresponde a la empresa ".$resultEmpresas->idEmpresa." - ".$resultEmpresas->empresa.".");
				
				if(!$serieBD = $this->validarSerieSucursal($serie, $sucursal['id'], $peticion->numeroReferenciaFacturacion))
					throw new Exception("186 - La serie $serie no corresponde a la sucursal ".$sucursal["sucursal"]." - ".$sucursal["clave"].".");
			}

			//if($serieBD["tipoComprobante"] !== $tipoDocumento)
			//	throw new Exception("187 - El tipo de Comprobante no corresponde a la serie ".$serie.".");
		
			$listFacturas = (new cFactura())->consultarFactura([
				'serie' 			=> $serie, 
				'fechaEmision' 		=> $fecha, 
				'numeroTicket' 		=> $numeroTicket, 
				'tipoDocumento' 	=> $tipoDocumento, 
				'estatus' 			=> 1, 
				'no_referencia' 	=> $peticion->numeroReferenciaFacturacion,
				'codigoSucursal' 	=> $codigoSucursal
			]);

	        $listFacturas1 = (new cFactura())->consultarFactura([
				'serie' 			=> $serie, 
				'fechaEmision' 		=> $fecha, 
				'numeroTicket' 		=> $numeroTicket, 
				'tipoDocumento' 	=> $tipoDocumento, 
				'estatus' 			=> 3, 
				'no_referencia' 	=> $peticion->numeroReferenciaFacturacion,
				'codigoSucursal' 	=> $codigoSucursal
			]);

			       $listFacturas2 = (new cFactura())->consultarFactura([
				'serie' 			=> $serie, 
				'fechaEmision' 		=> $fecha, 
				'numeroTicket' 		=> $numeroTicket, 
				'tipoDocumento' 	=> $tipoDocumento, 
				'estatus' 			=> 10, 
				'no_referencia' 	=> $peticion->numeroReferenciaFacturacion,
				'codigoSucursal' 	=> $codigoSucursal
			]);


			
			$listFacturas = json_decode($listFacturas, true);	
			$listFacturas1 = json_decode($listFacturas1, true);	
			$listFacturas2 = json_decode($listFacturas2, true);	

			//$listFacturas = null;

			if(!empty($listFacturas) or !empty($listFacturas1) or !empty($listFacturas2)){
				$factura = $listFacturas[0]; 
				$date 	 = date_create($factura['fechaEmision']);
				$id 	 = $factura['serie'].$factura['folio'];
				$xml 	 = ($peticion->regresaXML) ? ComprobanteFiscal::obtenerComprobante($id.".xml", $factura['cfdi']) : '';

				/*$dataList['id_cempresa'] = $factura['idEmpresa'];
				$resultEmpresa	= json_decode($empresa->consultarEmpresa($dataList));*/
				
				$pdf = '';
				if($peticion->regresaPDF){
					$pdfB64 = ComprobanteFiscal::generarComprobantePDF($id, $factura['cfdi'], $logo, $color);
					$pdf 	= ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdfB64));
				}
				
				$response["respuesta"]+= [
					"uuid" 				=> $factura['uuid'],
					"fechaTimbrado" 	=> date_format($date, 'Ymd'),
					"rfcEmisor" 		=> $rfcEmisor,
					"rfcReceptor" 		=> $factura['rfcReceptor'],
					"serie" 			=> $factura['serie'],
					"folio" 			=> $factura['folio'],
					"total" 			=> $factura['total'],
					"iva" 				=> $factura['iva'],
					"cfdiTimbrado" 		=> $xml,
					"pdf" 				=> $pdf,
					"numeroPeticion" 	=> $factura['peticion']
				];
				return $response;
			}
			
			//$this->getLogger('XML TICKET TIENDA:      '.$xml->xml);
			$objeto = json_decode($this->crearObjeto($xml->xml, $resultEmpresas->idEmpresa));
				error_log("crear objeto normal: ".print_r($objeto,1));
			if (!isset($objeto->Version)) {
				$detalles = [
					"origen" 						=> $origen, 
					"token" 						=> $resultEmpresas->token, 
					"logo" 							=> $logo, 
					"pdf" 							=> $peticion->regresaPDF, 
					"xml" 							=> $peticion->regresaXML,
					"numeroReferenciaFacturacion" 	=> $peticion->numeroReferenciaFacturacion,
					"peticion" 						=> uniqid(),
					"empresa" 						=> $resultEmpresas->idEmpresa,
					"colorConfiguracion" 			=> $color,
					"noTicket" 						=> $objeto->comprobante->noTicket,
					"codSuc" 						=> $codigoSucursal,
					"idSerie"						=> $serieBD['id'],
					"idSucursal"					=> $sucursal['id'],
					"idClub"						=> $sucursal['club']
				];
			}else {
				$detalles = [
					"origen" 						=> $origen, 
					"token" 						=> $resultEmpresas->token,
					"numeroReferenciaFacturacion" 	=> $peticion->numeroReferenciaFacturacion, 
					"logo" 							=> $logo, 
					"pdf" 							=> $peticion->regresaPDF, 
					"xml" 							=> $peticion->regresaXML,
					"peticion" 						=> uniqid(),
					"empresa" 						=> $resultEmpresas->idEmpresa,
					"colorConfiguracion" 			=> $color,
					"noTicket" 						=> $this->noTicket,
					"codSuc" 						=> $codigoSucursal,
					"idSerie"						=> $serieBD['id'],
					"idSucursal"					=> $sucursal['id'],
					"idClub"						=> $sucursal['club']
				];
			}
			$this->getLogger('Color2: ',$detalles['colorConfiguracion']);
			$emailCliente 		= isset($objeto->comprobante->receptor->email) ? $objeto->comprobante->receptor->email : '';
			$nombreCliente 		= isset($objeto->ComprobanteJson->Receptor->Nombre) ? $objeto->ComprobanteJson->Receptor->Nombre : $objeto->comprobante->receptor->nombre;
			// $membresiaCliente 	= $objeto->membresia;
			$membresiaCliente	= '';
			$concepto 			= isset($objeto->ComprobanteJson->Conceptos[0]->Descripcion) ? $objeto->ComprobanteJson->Conceptos[0]->Descripcion : $objeto->comprobante->conceptos[0]->descripcion;
			// error_log("emision detalles factura: ".print_r($detalles,1));

			$direccionesFactura = [
				'serie'=>isset($objeto->ComprobanteJson->Serie) ? $objeto->ComprobanteJson->Serie : $objeto->comprobante->serie,
				'folio'=>isset($objeto->ComprobanteJson->Folio) ? $objeto->ComprobanteJson->Folio : $objeto->comprobante->folio,
				'cempresa' => [
					'dato'=>isset($objeto->ComprobanteJson->Emisor->Rfc) ? $objeto->ComprobanteJson->Emisor->Rfc : $objeto->comprobante->emisor->rfc,
					'direccion'=>[
						'calle'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','calle'),
						'codigoPostal'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','codigoPostal'),
						'colonia'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','colonia'),
						'estado'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','estado'),
						'localidad'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','localidad'),
						'delegacion'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','municipio'),
						'noExterior'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','noExterior'),
						'noInterior'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','noInterior'),
						'ciudad'=>$this->getXmlValue($simpleXml,'//DomicilioFiscal','estado')
					]
				],
				'sucursal' => [
					'dato'=>$this->getXmlValue($simpleXml,'//ticketTienda','CodSuc'),
					'direccion'=>[
						'calle'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','calle'),
						'codigoPostal'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','codigoPostal'),
						'colonia'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','colonia'),
						'estado'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','estado'),
						'localidad'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','localidad'),
						'delegacion'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','municipio'),
						'ciudad'=>$this->getXmlValue($simpleXml,'//ExpedidoEn','estado')
					]
				]];

			//Verificamos si es una factura global
			if (strtoupper(substr($backNumTicket, 0, 1)) == 'G') {
				// unset($objeto['ComprobanteJson']['Receptor']['Nombre']);
				unset($objeto->ComprobanteJson->Receptor->Nombre);
			}


			$responseTimbrado 	= $this->Emitir($objeto, json_encode($detalles), $direccionesFactura);

			$date 				= date_create($responseTimbrado['fechaTimbrado']);
			$this->getLogger('ResponseTimbrado: ',$responseTimbrado);
			if (!isset($objeto->Version)) {
				$response["respuesta"]["uuid"] 				= $responseTimbrado['uuid'];
				$response["respuesta"]["fechaTimbrado"] 	= date_format($date, 'Ymd');
				$response["respuesta"]["rfcEmisor"] 		= $objeto->comprobante->emisor->rfc;
				$response["respuesta"]["rfcReceptor"] 		= $objeto->comprobante->receptor->rfc;
				$response["respuesta"]["serie"] 			= $objeto->comprobante->serie;
				$response["respuesta"]["folio"] 			= $responseTimbrado['folio'];
				$response["respuesta"]["total"] 			= $objeto->comprobante->total;
				$response["respuesta"]["iva"] 				= $objeto->comprobante->impuestos->totalImpuestosTrasladados;
				$response["respuesta"]["cfdiTimbrado"] 		= $responseTimbrado['cfdiTimbrado'];
				$response["respuesta"]["pdf"] 				= $responseTimbrado['pdf'];
				$response["respuesta"]["numeroPeticion"] 	= $detalles['peticion'];
			}else {
				$response["respuesta"]["uuid"] 				= $responseTimbrado['uuid'];
				$response["respuesta"]["fechaTimbrado"] 	= date_format($date, 'Ymd');
				$response["respuesta"]["rfcEmisor"] 		= $objeto->ComprobanteJson->Emisor->Rfc;
				$response["respuesta"]["rfcReceptor"] 		= $objeto->ComprobanteJson->Receptor->Rfc;
				$response["respuesta"]["serie"] 			= $objeto->ComprobanteJson->Serie;
				$response["respuesta"]["folio"] 			= $responseTimbrado['folio'];
				$response["respuesta"]["total"] 			= $objeto->ComprobanteJson->Total;
				$response["respuesta"]["iva"] 				= $objeto->ComprobanteJson->Impuestos->TotalImpuestosTrasladados;
				$response["respuesta"]["cfdiTimbrado"] 		= $responseTimbrado['cfdiTimbrado'];
				$response["respuesta"]["pdf"] 				= $responseTimbrado['pdf'];
				$response["respuesta"]["numeroPeticion"] 	= $detalles['peticion'];
			}
			
			if(!empty($emailCliente)){
				$params = array("email" 		=> $emailCliente,
								"valReemplazo" 	=> array('A1' => $nombreCliente,
													'A2' => date_format($date, 'd/m/Y'),
													'A3' => $membresiaCliente,
													'A4' => $objeto->comprobante->total,
													'A5' => $sucursal['clave']),
	        					"cliente" 		=> $nombreCliente,
								"id" 			=> $responseTimbrado['id'], 
								"from" 			=> $from,
								"tituloEmail" 	=> $subject,
								"cuerpoCorreo" 	=> $plantillaEmail,
								"copiaEmail" 	=> $copiaEmail,
								"copiaOculta" 	=> $copiaOculta,
								"codSuc" 		=> $codigoSucursal,
								"empresa" 		=> $resultEmpresas->idEmpresa);
				$this->getLogger('Paso 8a');
				$this->enviarEmail(json_encode($params));
			}
			
			//Borrar Archivos adjuntos
			unlink(dirname(__FILE__)."/comprobante/tmp/xml/".$responseTimbrado['id'].".xml");
			unlink(dirname(__FILE__)."/comprobante/tmp/pdf/".$responseTimbrado['id'].".pdf");
		}catch(Exception $e){
            $response["respuesta"]['estatus'] 		= 0;
			$response["respuesta"]['descripcion'] 	= $e->getMessage();
		}
	
		$origenTxt = ' Web Service';
		$origenTxt = ($origen == 2) ? ' Portal' : $origenTxt;
		$this->logger->info("Fin de petición con ".$numReferenciaTxt.$origenTxt);
		
		return $response;
	}

	public function EmitirCFDIComplementos($peticion) {
		$response = [
			"respuesta" => [
				"estatus" => 1, 
				"descripcion" => "OK",
				"numeroReferenciaFacturacion" => $peticion->numeroReferenciaFacturacion
			]
		];

	    try {
	    	$ticketTienda = $peticion->sxml->xpath('//ticketTienda');
	    	$emisor = $peticion->sxml->xpath('//Emisor');

			$serie 			 = (string)$ticketTienda[0]->attributes()->serie;
			$empresa 		 = (string)$emisor[0]->attributes()->nombre;
			$fecha 			 = (string)$ticketTienda[0]->attributes()->Fecha;
			$fechaTicket 	 = str_replace("/", "-", (string)$ticketTienda[0]->attributes()->FechaTicket);
			$horaTicket 	 = (string)$ticketTienda[0]->attributes()->HoraTicket;
			$fechaHoraTicket = date('Y-m-d', strtotime($fechaTicket))." ".$horaTicket;
			$numeroTicket 	 = (string)$ticketTienda[0]->attributes()->NumTicket;
			$codigoSucursal  = (string)$ticketTienda[0]->attributes()->CodSuc;
			$emisor 		 = $peticion->sxml->xpath('//Emisor');
			
			$tipoDocumento 	 = (string)$ticketTienda[0]->attributes()->tipoDeComprobante;

			$rfcEmisor 		 = (string)$emisor[0]["rfc"];
			$nombreEmisor 	 = (string)$emisor[0]["nombre"];

			$resultEmpresas  = json_decode((new cEmpresa())->consultarEmpresa([
				'activo' => 1,				
				'rfc' => $rfcEmisor
			]));

			$this->getLogger('Empresa: ',$resultEmpresas);

	        if(!$sucursal = $this->validarSucursalEmpresa($codigoSucursal, $resultEmpresas->idEmpresa, $peticion->numeroReferenciaFacturacion))
					throw new Exception("185 - La sucursal ".$codigoSucursal." no corresponde a la empresa ".$resultEmpresas->idEmpresa." - ".$resultEmpresas->empresa.".");

			if(!$serieBD = $this->validarSerieSucursal($serie, $sucursal['id'], $peticion->numeroReferenciaFacturacion))
				throw new Exception("186 - La serie $serie no corresponde a la sucursal ".$sucursal["sucursal"]." - ".$sucursal["clave"].".");


			require_once('../_complementos_enviar/DB/cComplemento.php');
			require_once('../_complementos_archivos/plantillapdf.php');

			$facturas = (new cComplemento())->checkFactura(md5($peticion->xml['xml']));

			if (count($facturas) > 0) {
				$this->getLogger('Factura encontrada!',$facturas);
				$base64 = base64_decode($facturas[0]['v_json']);
				$factura = json_decode($base64,true);
				$this->getLogger('Factura PDF: ',$factura);

				if ($peticion->regresaPDF != 0) {
					$this->getLogger('Factura para pdf: ',$factura);
					$tmpLogo = $factura['ComprobanteJson']['Configuracion']['tmplogo'];
					list(,$tmpLogo) = explode(';', $tmpLogo);
					list(,$tmpLogo) = explode(',', $tmpLogo);
					$logo = base64_decode($tmpLogo);
					$logotipo = '../_complementos_enviar/tmp/'.$factura['ComprobanteJson']['Emisor']['Rfc'].uniqid('_tmp').'.jpg';
					if (!file_exists('tmp'))
						mkdir('tmp',0777);
					file_put_contents($logotipo, $logo);
					$factura['ComprobanteJson']['Configuracion']['logo'] = $logotipo;
					$pdf = generarMiPDF($factura,null,['name'=>'factura.php','tipo'=>'S']);
					$pdf = base64_encode($pdf);
				}
				else
					$pdf = '';

				if ($peticion->regresaXML != 0)
					$xml = $facturas[0]['v_xml'];
				else
					$xml = '';

				$date 										= date_create($factura['ComprobanteJson']['Timbrado']['fechaTimbrado']);
				$response["respuesta"]["uuid"] 				= $factura['ComprobanteJson']['Timbrado']['uuid'];
				$response["respuesta"]["fechaTimbrado"] 	= date_format($date, 'Ymd');
				$response["respuesta"]["rfcEmisor"] 		= $factura['ComprobanteJson']['Emisor']['Rfc'];
				$response["respuesta"]["rfcReceptor"] 		= $factura['ComprobanteJson']['Receptor']['Rfc'];
				$response["respuesta"]["serie"] 			= $factura['ComprobanteJson']['Serie'];
				$response["respuesta"]["folio"] 			= $factura['ComprobanteJson']['Timbrado']['folio'];
				$response["respuesta"]["total"] 			= $factura['ComprobanteJson']['Total'];
				$response["respuesta"]["iva"] 				= '0.00';
				$response["respuesta"]["cfdiTimbrado"] 		= $xml;
				$response["respuesta"]["pdf"] 				= $pdf;
				$response["respuesta"]["numeroPeticion"] 	= $factura['ComprobanteJson']['Timbrado']['peticion'];

				if ($peticion->regresaPDF == 0)
					unset($response['respuesta']['pdf']);

				if ($peticion->regresaXML == 0)
					unset($response['respuesta']['cfdiTimbrado']);
			}else {
				$this->getLogger('Factura no encontrada!',$facturas);
				$parametroService 	= new tConfiguracionSistema();
	        	$parametroService->consultar(['empresa' => $resultEmpresas->idEmpresa]);

		        $logo 				= $parametroService->obtenParametro("LOGO");
	        	$color 				= $parametroService->obtenParametro("COLOR_BACKGROUND");
	        	$diasGracia 		= $parametroService->obtenParametro("DIAS_LIMITE");
	        	$from 				= $parametroService->obtenParametro("CEMAIL_FROM");
	        	$subject 			= $parametroService->obtenParametro("CEMAIL_SUBJECT");
	        	$plantillaEmail 	= $parametroService->obtenParametro("CEMAIL_CONTENT");
	        	$copiaEmail 		= $parametroService->obtenParametro("CEMAIL_CC");
	        	$copiaOculta 		= $parametroService->obtenParametro("CEMAIL_CO");
	        	$copiaEmail 		= ($copiaEmail == 'undefined' || empty($copiaEmail)) ? '' : $copiaEmail;
				$copiaOculta 		= ($copiaOculta == 'undefined' || empty($copiaOculta)) ? '' : $copiaOculta;
	        	$refFacturaGlobal 	= mb_substr($peticion->numeroReferenciaFacturacion, 0, 1);

	        	$detalles = array(
					'origen'=>3,
					'token'=>$resultEmpresas->token,
					'logo'=>'',
					'pdf'=>'',
					'xml'=>'',
					'numeroReferenciaFacturacion'=>0,
					'peticion'=>uniqid(),
					'empresa'=>$resultEmpresas->idEmpresa,
					'colorConfiguracion'=>'000000',
					'codSuc'=>$codigoSucursal,
					'idSerie'=>$sucursal['id'],
					'idSucursal'=>$sucursal['id']
				);

				$objeto = $this->crearObjeto($peticion->xml['xml'],$resultEmpresas->idEmpresa);
				$objJson = json_decode($objeto,true);

				if (isset($objJson['ComprobanteJson'])) {
					ob_start();

					$this->getLogger('Objeto: ',json_decode($objeto));
					$this->getLogger('Detalles: ',$detalles);
					$respuesta = $this->Emitir(json_decode($objeto),json_encode($detalles));
					 ob_end_clean();

					$this->getLogger('Respuesta:',$respuesta);

					########################
					# Obtenemos los datos de dirección del receptor, emisor y lugar de expedicion
					$factura = $objJson;
					$q = mysql::getInstance();
					$dbEmisor = $q->selectGenerico('SELECT d.calle,d.no_exterior,d.no_interior,d.colonia,d.delegacion_municipio,d.estado,d.codigo_postal,c.rfc,c.razon_social,c.regimen,c.v_token,c.id_cempresa FROM cempresa c LEFT JOIN cdomicilio d ON c.id_cdomicilio = d.id_cdomicilio WHERE c.id_cempresa='.$resultEmpresas->idEmpresa);
					// $dbReceptor = $q->selectGenerico('SELECT d.calle,d.no_exterior,d.no_interior,d.colonia,d.delegacion_municipio,d.estado,d.codigo_postal,c.razon_social FROM ccliente c LEFT JOIN cdomicilio d ON c.id_cdomicilio = d.id_cdomicilio WHERE c.rfc="'.$objJson['ComprobanteJson']['Receptor']['Rfc'].'" AND c.activo=1');

					//Agregamos los datos de timbrado
					$factura['ComprobanteJson']['Timbrado'] = $respuesta;
					$factura['ComprobanteJson']['Timbrado']['peticion'] = $detalles['peticion'];

					//Emisor
					$factura['ComprobanteJson']['Emisor'] = array_merge($factura['ComprobanteJson']['Emisor'], array(
						'Direccion' => $dbEmisor[0]['calle'].' No Ext:'.$dbEmisor[0]['no_exterior'].' No Int:'.$dbEmisor[0]['no_interior'],
						'Colonia' => $dbEmisor[0]['colonia'],
						'Municipio' => $dbEmisor[0]['delegacion_municipio'].' CP:'.$dbExpedicion[0]['codigo_postal'],
						'Estado' => $dbEmisor[0]['estado'],
					));

					//Receptor
					// $factura['ComprobanteJson']['Receptor'] = array_merge($factura['ComprobanteJson']['Receptor'], array(
					// 	'Direccion' => $dbReceptor[0]['calle'].' No Ext:'.$dbReceptor[0]['no_exterior'].' No Int:',
					// 	'Colonia' => $dbReceptor[0]['colonia'],
					// 	'Municipio' => $dbReceptor[0]['delegacion_municipio'],
					// 	'Estado' =>  $dbReceptor[0]['estado']
					// ));
					$factura['ComprobanteJson']['Receptor'] = [];

					//Lugar de expedicion
					$sql = 'SELECT d.calle,d.no_exterior,d.no_interior,d.colonia,d.delegacion_municipio,d.estado,d.codigo_postal,c.csucursal,c.clave FROM csucursal c LEFT JOIN cdomicilio d ON c.id_cdomicilio = d.id_cdomicilio WHERE c.id_cempresa = "'.$dbEmisor[0]['id_cempresa'].'" AND c.id_csucursal = '.$sucursal['id'];
					$dbExpedicion = $q->selectGenerico($sql);

					$factura['ComprobanteJson']['LugarExpedicion'] = array(
						'NoSucursal' => $codigoSucursal,
						'Sucursal' => $dbExpedicion[0]['clave'],
						'Direccion' => $dbExpedicion[0]['calle'].' No Ext:'.$dbExpedicion[0]['no_exterior'].' No Int:'.$dbExpedicion[0]['no_interior'],
						'Colonia' => $dbExpedicion[0]['colonia'],
						'Municipio' => $dbExpedicion[0]['delegacion_municipio'].' CP:'.$dbExpedicion[0]['codigo_postal'],
						'Estado' => $dbExpedicion[0]['estado']
						);

					//Configuración de clubes
					// $sql = 'SELECT id_cclub, v_descripcion, v_logotipo, v_color FROM cclub JOIN csucursal ON id_cclub = fk_id_club WHERE id_cempresa = '.$dbEmisor[0]['id_cempresa'].' AND csucursal = '.$infoSucursal[1];
					// $dbConf = $q->selectGenerico($sql);

					// $factura['ComprobanteJson']['Configuracion'] = array(
					// 		'tmplogo'=>$dbConf[0]['v_logotipo'],
					// 		'color'=>$dbConf[0]['v_color'],
					// 		'logo'=>''
					// 	);
					$parametroService = new tConfiguracionSistema();
					$parametroService->consultar(['empresa' => $dbEmisor[0]['id_cempresa']]);

					$factura['ComprobanteJson']['Configuracion'] = array(
							'tmplogo'=>$parametroService->obtenParametro("LOGO"),
							'color'=>$parametroService->obtenParametro("COLOR_BACKGROUND"),
							'logo'=>''
						);

					if ($peticion->regresaPDF != 0) {
						// configuracion de logo
						$tmpLogo = $factura['ComprobanteJson']['Configuracion']['tmplogo'];
						list(,$tmpLogo) = explode(';', $tmpLogo);
						list(,$tmpLogo) = explode(',', $tmpLogo);
						$logo = base64_decode($tmpLogo);
						$logotipo = '../_complementos_enviar/tmp/'.$factura['ComprobanteJson']['Emisor']['Rfc'].uniqid('_tmp').'.jpg';
						if (!file_exists('tmp'))
							mkdir('tmp',0777);
						file_put_contents($logotipo, $logo);
						$factura['ComprobanteJson']['Configuracion']['logo'] = $logotipo;
						
						$pdf = generarMiPDF($factura,null,['name'=>'factura.php','tipo'=>'S']);
					}else {
						$pdf = '';
					}
					######################################
					#
					# Guardamos en DB
					# 
					######################################

					require_once('../_complementos_enviar/DB/cComplementoPago.php');
					require_once('../_complementos_enviar/DB/cComplementoPagoRelacion.php');

					$tipoRelacion = (isset($objJson['ComprobanteJson']['CfdiRelacionados']['TipoRelacion']) ? $objJson['ComprobanteJson']['CfdiRelacionados']['TipoRelacion'] : null);
					$idComplemento = (new cComplemento())->nuevo_complemento($dbEmisor[0]['id_cempresa'],$sucursal['id'],$respuesta['uuid'],$respuesta['folio'],$serie,$objJson['ComprobanteJson']['Emisor']['Nombre'],$objJson['ComprobanteJson']['Emisor']['Rfc'],$tipoRelacion,$respuesta['fechaTimbrado'],md5($peticion->xml['xml']),$detalles['peticion'],2,base64_encode(json_encode($factura)),$respuesta['xml']);
					
					$pagos = $objJson['ComprobanteJson']['Complemento']['Items'][0]['Pago'];
					foreach($pagos as $pago) {
						$this->getLogger('PAGO: ',$pago);
						$idPago = (new cComplementoPago())->nuevo_pago($idComplemento,$pago['FormaDePagoP'],$pago['MonedaP'],$pago['TipoCambio'],$pago['Monto'],$pago['NumOperacion'],str_replace('T', ' ', $pago['FechaPago']));

						foreach($pago['DoctoRelacionado'] as $fac) {
							$idFact = (new cComplementoPagoRelacion())->nuevo_doc($idComplemento,$idPago,0,$fac['IdDocumento'],$fac['MetodoDePagoDR'],$fac['MonedaDR'],$fac['Serie'],$fac['ImpSaldoAnt'],$fac['ImpPagado'],$fac['ImpSaldoInsoluto'],$fac['NumParcialidad']);
						}
					}
					
					
					unlink($logotipo);

					$objeto = json_decode($objeto);

					$date 										= date_create($respuesta['fechaTimbrado']);
					$response["respuesta"]["uuid"] 				= $respuesta['uuid'];
					$response["respuesta"]["fechaTimbrado"] 	= date_format($date, 'Ymd');
					$response["respuesta"]["rfcEmisor"] 		= $objeto->ComprobanteJson->Emisor->Rfc;
					$response["respuesta"]["rfcReceptor"] 		= $objeto->ComprobanteJson->Receptor->Rfc;
					$response["respuesta"]["serie"] 			= $objeto->ComprobanteJson->Serie;
					$response["respuesta"]["folio"] 			= $respuesta['folio'];
					$response["respuesta"]["total"] 			= $objeto->ComprobanteJson->Total;
					$response["respuesta"]["iva"] 				= '0.00';
					$response["respuesta"]["cfdiTimbrado"] 		= $respuesta['xml'];
					$response["respuesta"]["pdf"] 				= base64_encode($pdf);
					$response["respuesta"]["numeroPeticion"] 	= $detalles['peticion'];

					if ($peticion->regresaXML == 0)
						unset($response['respuesta']['cfdiTimbrado']);

					if ($peticion->regresaPDF == 0)
						unset($response['respuesta']['pdf']);
				}else {
					$mssg = 'Se encontraron los siguientes errores: ';
					$mssg .= implode(', ',$objJson);

					$response['respuesta']['estatus'] = 0;
					$response['respuesta']['descripcion'] = $mssg;
				}
			}
		}catch(Exception $e) {
			$response['respuesta']['estatus'] = 0;
			$response['respuesta']['descripcion'] = $e->getMessage();
		}

		$this->getLogger('Response: ',$response);

		return $response;
	}

	public function ConsultarCFDI($factura)
	{
		$facturas = array();
		$totalFacturas = 0;
		$respuesta;
		$mensaje = "OK";
		$estatus = 1;

		$data = array('usuario' 				=> $factura->usuario,
					'password' 					=> $factura->password,
					'id_cconfiguracion_sistema' => 1,
					'id_cempresa' 				=> $factura->empresa);

		$empresa 	= new cEmpresa();
		$result 	= json_decode($empresa->consultarEmpresa($data));
		
		if($factura->usuario != self::USUARIO || $factura->password != self::PASSWORD){
			$estatus = 0;
			$mensaje = "156 - Usuario o Contraseña inválidos.";
		//}elseif (!$this->validaReferencia($result->idEmpresa, $factura->numeroReferenciaFacturacion)){
		//	$estatus = 0;
		//	$mensaje = "ERROR: Número de referencia inválido: ".$factura->numeroReferenciaFacturacion;
		}else{
			$estatusPeticion = isset($factura->estatusDocumento) ? $factura->estatusDocumento : '';

			if(empty($estatusPeticion))
				$estatusPeticion = "0, 1";
			elseif($estatusPeticion == "V")
				$estatusPeticion = "1";
			elseif($estatusPeticion == "C")
				$estatusPeticion = "0";
			else
				$estatusPeticion = "20";

			$dataFactura = array("id_cempresa" 		=> $factura->empresa,
						"usuario" 					=> $factura->usuario,
						"password" 					=> $factura->password,
						"no_referencia" 			=> ($factura->numeroReferenciaFacturacion == "") ? null : $factura->numeroReferenciaFacturacion,
						"UUID" 						=> $factura->uuid,
						"csucursal" 				=> $factura->centroCostos,
						"estatus" 					=> $estatusPeticion,
						"tipoDocumento" 			=> (!empty($factura->tipoDocumento)) ? $factura->tipoDocumento : '',
						"fecha_timbradoInicial" 	=> $factura->fechaInicial,
						"fecha_timbradoFinal" 		=> $factura->fechaFinal);

			$tablaFactura 	= new cFactura();
			$listFacturas 	= json_decode($tablaFactura->consultarFactura($dataFactura));

			$parametroService = new tConfiguracionSistema();
    		$parametroService->consultar(['empresa' => $result->idEmpresa]);
        	$logo  = $parametroService->obtenParametro("LOGO");
    		$color = $parametroService->obtenParametro("COLOR_BACKGROUND");

			foreach ($listFacturas as $elemento){
				$id = $elemento->serie.$elemento->folio;
				$date = date_create($elemento->fechaTimbrado);

				$tipoClub = $this->obtenerConfiguracionClub($dataFactura['csucursal'], $dataFactura['id_cempresa']);
				if(!empty($tipoClub)){
					if(strlen($tipoClub['logotipo']) > 100)
						$logo 	= $tipoClub['logotipo'];

					if(strlen($tipoClub['color']) == 7)
						$color = $tipoClub['color'];
				}

				$xml = ($factura->regresaXML) ? ComprobanteFiscal::obtenerComprobante($id.".xml", $elemento->cfdi) : '';

				$pdf = '';
				if($factura->regresaPDF){
					$pdfCadena 	= ComprobanteFiscal::generarComprobantePDF($id, $elemento->cfdi, $logo, $color);
					$pdf 		= ComprobanteFiscal::obtenerComprobante($id.".pdf", base64_encode($pdfCadena));
				}

				$elementoFactura = array(
								"numeroReferenciaFacturacion" 	=> $elemento->referencia,
								"uuid" 							=> $elemento->uuid,
								"serie" 						=> $elemento->serie,
								"folio" 						=> $elemento->folio,
								"rfcEmisor" 					=> $result->rfc,
								"rfcReceptor" 					=> $elemento->rfcReceptor,
								"fechaTimbrado" 				=> date_format($date, 'Ymd'),
								"total" 						=> $elemento->total,
								"subTotal" 						=> $elemento->subtotal,
								"iva" 							=> $elemento->iva,
								"tipoDocumento" 				=> ($elemento->tipoDocumento == 'I') ? 'Factura' : 'Nota de crédito',
								"estatusDocumento" 				=> $elemento->estatus ? "V" : "C",
								"cfdiTimbrado" 					=> $xml,
								"pdf" 							=> $pdf);

				if($factura->traerCamposDetalle){
					$elementoFactura["metodoDePago"] 	= $elemento->metodoPago;
					$elementoFactura["moneda"] 			= $elemento->moneda;
					$elementoFactura["tipoCambio"] 		= $elemento->tipoCambio;
					$elementoFactura["numeroTicket"] 	= $elemento->numeroTicket;
				}

				$totalFacturas++;
				array_push($facturas, $elementoFactura);
			}
		}
		
		$respuesta = array("estatus" 		=> $estatus, 
						"descripcion" 		=> $mensaje,
						"totalFacturas" 	=> $totalFacturas,
						"numeroPeticion" 	=> uniqid(),
						"Facturacion"		=> $facturas);

		return $respuesta;
	}

	public function CancelarCFDI($factura)
	{
		
		$respuesta;
		$noPeticion = uniqid();



		$cancelacion = array("noCertificado" 	=> $certificado,
							"rfcEmisor" 		=> $rfcEmisor,
							"uuid" 				=> $factura->uuid,
							"fechaTimbrado"		=> $result->fechaTimbrado);

		$respuesta = array("respuesta" => array(
								"estatus" 						=> 1,
								"descripcion" 					=> "OK",
								"numeroReferenciaFacturacion" 	=> $factura->numeroReferenciaFacturacion,
								"numeroPeticion" 				=> $noPeticion,
								"uuid" 							=> $factura->uuid));

		$parametros = json_encode($respuesta);

		$origen = 1;
		$respuesta;

		if(strpos($factura->usuario, "_")){
			$origen = explode("_", $factura->usuario);
			$factura->usuario = $origen[0];
			$origen = $origen[1];
		}

		$data['usuario'] 					= $factura->usuario;
		$data['password'] 					= $factura->password;
		$data['activo'] 					= 1;
		//$data['id_cconfiguracion_sistema'] 	= 1;

		$empresa 	= new cEmpresa();
		$tablaFactura 	= new cFactura();

		$listData 	= json_decode($tablaFactura->buscarFactura($factura->uuid));
		$result 	= json_decode($empresa->consultarEmpresa($data));

		$dataFactura = array("UUID"	=> $factura->uuid);
		$listFactura 	= json_decode($tablaFactura->consultarFactura($dataFactura));

		foreach($listFactura as $lf){
			$estatus = $lf->estatus;
			$rfcReceptor = $lf->rfcReceptor;
			$total = $lf->total;
			$idfactura=$lf->idFactura;
		}


		$detalles = array("origen" 							=> $origen, 
							"token" 						=> $listData->v_token[0],
							"numeroReferenciaFacturacion" 	=> $factura->numeroReferenciaFacturacion,
							"peticion" 						=> uniqid(),
							"empresa" 						=> $listData->id_cempresa[0],
							"certificado" 					=> $listData->ccertificado[0],
							"RfcEmisor" 					=> $listData->rfc[0],
							"RfcReceptor" 					=> $rfcReceptor,
							'Total' 	    	            => $total,
							"Id"                            => $idfactura,
							"fechaTimbrado" 				=> $listData->fecha_timbrado[0]);

		switch ($estatus) {
							case 0:
							$status='Cancelada';
						    break;
							case 1:
							$status='Vigente';
						    break;
							case 2:
							$status='Pendiente de envio';
							break;
							case 3:
							$status='En proceso';
							break;
							case 4:
							$status='Previamente Cancelada';
						    break;
							case 5:
							$status='Error de estructura';
							break;
							case 6:
							$status='Error';
							break;
							case 7:
							$status='Cancelado sin aceptación';
						    break;
							case 8:
							$status='Cancelada con aceptación';
							break;
							case 9:
					        $status='Afirmativa ficta';
							break;
							case 10:
							$status='Rechazado';
							break;
							case 11:
							$status='No cancelable';
							break;

						}

		if(empty($listFactura)){
			$respuesta = array("respuesta" 	=> array(
				"estatus"						=>	"0",
				"descripcion"					=>	"161 - La factura a cancelar no existe",
				"numeroReferenciaFacturacion"	=>	"",
				"numeroPeticion"				=>	$noPeticion,
				"uuid"							=>	$factura->uuid));
		}elseif($estatus <> 1){
			$respuesta = array("respuesta" 	=> array(
				"estatus"						=>	$estatus,
				"descripcion"					=>	"162 - Esta factura cuenta con estatus de cancelación "."-".$status."-",
				"numeroReferenciaFacturacion"	=>	"",
				"numeroPeticion"				=>	$noPeticion,
				"uuid"							=>	$factura->uuid));				

		}else{
			try{
				$cancelacion 	= PAC::cancelar($parametros, $detalles);
				$estatusRespuesta = ($cancelacion->estatus == "200") ? '1' : '3' ;
				$respuesta = array("respuesta" 	=> array(
									"estatus"						=>	$estatusRespuesta,
									"descripcion"					=>	$cancelacion->mensaje,
									"numeroReferenciaFacturacion"	=>	$factura->numeroReferenciaFacturacion,
									"numeroPeticion"				=>	$noPeticion,
									"uuid"							=>	$cancelacion->uuid));
				if($cancelacion->estatus == "200"){
					$tablaFactura->actualizarFactura($cancelacion->uuid);
				}
			}catch(Exception $e){
				$respuesta = array("respuesta" 	=> array(
				"estatus"						=>	$estatus,
				"descripcion"					=>	$e->getMessage(),
				"numeroReferenciaFacturacion"	=>	"",
				"numeroPeticion"				=>	$noPeticion,
				"uuid"							=>	$factura->uuid));
			}
		}
		return $respuesta;
	} /* termina funcion cancelar */

	/**
	 * Verifica la versión de CFDI y dependiendo se envia a crearObjeto32() o crearObjeto33()
	 * 
	 * @param  string $xml       El contenido de ticketTienda (XML)
	 * @param  int    $idEmpresa La ID de la empresa
	 * @return string 			 Retorna el JSON ya generado
	 */
	private function crearObjeto($xml,$idEmpresa) {
		$simplexml = simplexml_load_string($xml);
		$ticketTienda = $simplexml->xpath('//ticketTienda');

		$version = (string)$ticketTienda[0]->attributes()->version;
		$this->getLogger('Versión CFDI: ',$version);
		if ($version != '3.3')
			return $this->crearObjeto32($xml,$idEmpresa);
		else
			return $this->crearObjeto33($xml,$idEmpresa);
	}

	private function crearObjeto32($xml, $idEmpresa) {
		$simplexml = simplexml_load_string($xml);

		$objJson = array(
				'tipo'=>'JSON',
				'comprobante'=>array(),
				'noControl'=>uniqid()
			);

		#########################################################
		# Bloque "Comprobante"
		
		//Accesos a DB
		error_log('CodSuc: '.$this->getXmlValue($simplexml,'//ticketTienda','CodSuc'));
		$tmpSucursal = (new cSucursal())->consultar([			
			'sucursal'	=> $this->getXmlValue($simplexml,'//ticketTienda','CodSuc')	
		]);

		$tmpTipoComprobante = strtoupper(substr($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'), 0, 1));
		error_log('tipoComprobante: '.$tmpTipoComprobante);
		$tmpFolio = (new cSerie())->consultar([
			"sucursal"		=> $tmpSucursal[0]['id'],
			"tipoComprobante"	=> $tmpTipoComprobante
		]);

		$tmpCertificado = (new cCertificado())->consultar([
				'id_cempresa'=>$tmpSucursal[0]['empresa']
			]);
		
		$objComprobante = array(
				'version'=>'3.2',
				'fecha'=>$this->getXmlValue($simplexml,'//ticketTienda','fecha'),
				'sello'=>'',
				'formaDePago'=>$this->getXmlValue($simplexml,'//ticketTienda','formaDePago'),
				'noCertificado'=>$tmpCertificado[0]['ccertificado'],
				'certificado'=>'',
				'condicionesDePago'=>$this->getXmlValue($simplexml,'//ticketTienda','condicionesDePago','NO ESPECIFICADO'),
				'subTotal'=>$this->getXmlValue($simplexml,'//ticketTienda','subTotal'),
				'descuento'=>$this->getXmlValue($simplexml,'//ticketTienda','descuento'),
				'descuentoSpecified'=>true,
				'motivioDescuento'=>'0',
				'tipoCambio'=>'1.0',
				'moneda'=>'MXP',
				'total'=>$this->getXmlValue($simplexml,'//ticketTienda','total'),
				'tipoDeComprobante'=>$this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'),
				'metodoDePago'=>'',
				'lugarExpedicion'=>$this->getXmlValue($simplexml,'//ticketTienda','LugarExpedicion'),
				'folioFiscalOrigSpecified'=>false,
				'serieFolioFiscalOrigSpecified'=>false,
				'fechaFolioFiscalOrigSpecified'=>false,
				'montoFolioFiscalOrigSpecified'=>false,
				'serie'=>$this->getXmlValue($simplexml,'//ticketTienda','serie'),
				'folio'=>$tmpFolio[0]['folioActial'],
				'noTicket'=>$this->getXmlValue($simplexml,'//ticketTienda','NumTicket'),
				'emisor'=>'',
				'receptor'=>'',
				'conceptos'=>'',
				'impuestos'=>'',
				'addenda'=>''
			);

		#########################################################
		# Subloque de objJson "metodoPago"
		
		$metodoPago = $simplexml->MetodosPagoTicket[0];
		if (is_object($metodoPago) && count($metodoPago) >= 1) {
			$tmpMetodoPago = '';
			//$this->getLogger('MetodoPago 32:',print_r($metodoPago,1));
			foreach($metodoPago as $metodo) {
				$tmpMetodoPago .= (empty($tmpMetodoPago)) ? (string)$metodo->attributes()->des : ','.(string)$metodo->attributes()->des;
			}

			$objComprobante['metodoDePago'] = $tmpMetodoPago;
		}

		#########################################################
		# Subloque de objJson "emisor"

		$objEmisor = array(
				'nombre'=>$this->getXmlValue($simplexml,'//Emisor','nombre'),
				'rfc'=>$this->getXmlValue($simplexml,'//Emisor','rfc'),
				'regimenFiscal'=>array(
						array('regimen'=>$this->getXmlValue($simplexml,'//Emisor/RegimenFiscal','Regimen'))
					),
				'domicilioFiscal'=>array(
						'calle'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','calle'),
						'municipio'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','municipio'),
						'estado'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','estado'),
						'pais'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','pais'),
						'codigoPostal'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','codigoPostal'),
						'noExterior'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','noExterior'),
						'colonia'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','colonia'),
						'localidad'=>$this->getXmlValue($simplexml,'//Emisor/DomicilioFiscal','localidad')
					),
				'expedidoEn'=>array(
						'calle'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','calle'),
						'municipio'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','municipio'),
						'estado'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','estado'),
						'pais'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','pais'),
						'codigoPostal'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','codigoPostal'),
						'noExterior'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','noExterior'),
						'colonia'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','colonia'),
						'localidad'=>$this->getXmlValue($simplexml,'//Emisor/ExpedidoEn','localidad')
					)
			);

		$objComprobante['emisor'] = $objEmisor;

		#########################################################
		# Subloque de objJson "receptor"
		
		$objReceptor = array(
				'nombre'=>$this->getXmlValue($simplexml,'//Receptor','nombre'),
				'rfc'=>$this->getXmlValue($simplexml,'//Receptor','rfc'),
				'domicilio'=>array(
						'calle'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','calle'),
						'municipio'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','municipio'),
						'estado'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','estado'),
						'pais'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','pais'),
						'codigoPostal'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','codigoPostal'),
						'noExterior'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','noExterior'),
						'colonia'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','colonia'),
						'localidad'=>$this->getXmlValue($simplexml,'//Receptor/Domicilio','localidad')
					)
			);

		$objComprobante['receptor'] = $objReceptor;

		#########################################################
		# Subloque de objJson "conceptos"
		
		$conceptos = $simplexml->Conceptos[0];
		$this->getLogger('isObject: '.is_object($conceptos).' num:'.count($conceptos));
		if (is_object($conceptos) && count($conceptos) >= 1) {
			$objConceptos = array();
			foreach($conceptos as $tmpConcepto) {
				$objConcepto = array(
						'cantidad'=>$this->getXmlValue($tmpConcepto,'','cantidad'),
						'unidad'=>$this->getXmlValue($tmpConcepto,'','unidad'),
						'descripcion'=>$this->getXmlValue($tmpConcepto,'','descripcion'),
						'valorUnitario'=>$this->getXmlValue($tmpConcepto,'','valorUnitario'),
						'importe'=>$this->getXmlValue($tmpConcepto,'','importe'),
						'claveUnidad'=>$this->getXmlValue($tmpConcepto,'','noIdentificacion'),
						'claveProdServ'=>'',
						'descuento'=>'0'
					);

				array_push($objConceptos,$objConcepto);
			}

			$objComprobante['conceptos'] = $objConceptos;
		}

		#########################################################
		# Subloque de objJson "impuestos"
		
		$impuestos = $simplexml->Impuestos[0];
		$totalImpuestosTrasladados = 0;
		$totalImpuestosRetenidos = 0;
		
		if (is_object($impuestos) && count($impuestos) >= 1) {

			$tmpObjRetenidos = array();
			foreach($impuestos->Retenidos as $impuestoRetenido) {
				array_push($tmpObjRetenidos,array(
						'impuesto'=>$this->getXmlValue($impuestoRetenido->Retenido,'','impuesto'),
						'tasa'=>$this->getXmlValue($impuestoRetenido->Retenido,'','tasa'),
						'importe'=>$this->getXmlValue($impuestoRetenido->Retenido,'','importe')
					));
				$totalImpuestosRetenidos += $this->getXmlValue($impuestoRetenido->Retenido,'','importe');
			}

			$tmpObjTraslados=array();
			foreach($impuestos->Traslados as $impuestoTraslado) {
				array_push($tmpObjTraslados,array(
						'impuesto'=>$this->getXmlValue($impuestoTraslado->Traslado,'','impuesto'),
						'tasa'=>$this->getXmlValue($impuestoTraslado->Traslado,'','tasa'),
						'importe'=>$this->getXmlValue($impuestoTraslado->Traslado,'','importe')
					));
				$totalImpuestosTrasladados += $this->getXmlValue($impuestoTraslado->Traslado,'','importe');
			}

			$objImpuestos = array();
			$objImpuestos['totalImpuestosRetenidos'] = $totalImpuestosRetenidos;
			$objImpuestos['totalImpuestosRetenidosSpecified'] = $totalImpuestosRetenidos;
			$objImpuestos['totalImpuestosTrasladados'] = $totalImpuestosTrasladados;
			$objImpuestos['totalImpuestosTrasladadosSpecified'] = $totalImpuestosTrasladados;

			if (count($tmpObjRetenidos) > 0) {
				$objImpuestos['retenidos'] = $tmpObjRetenidos;
			}

			if (count($tmpObjTraslados) > 0) {
				$objImpuestos['traslados'] = $tmpObjTraslados;
			}

			if (count($objImpuestos) > 0) {
				$objComprobante['impuestos'] = $objImpuestos;

			}else
				unset($objComprobante['impuestos']);
		}
		$objJson['comprobante'] = $objComprobante;

		#########################################################
		# Subloque de objJson "addenda"
		
		$xmlAddenda = '
			<TMARTI>
				<GENERALES>
					<TIPO_DOCUMENTO>'.$this->getXmlValue($simplexml,'//ticketTienda','TipoDocumento').'</TIPO_DOCUMENTO>
					<SUCURSAL>
						<CODIGO_SUCURSAL>'.$this->getXmlValue($simplexml,'//ticketTienda','CodSuc').'</CODIGO_SUCURSAL>
						<NOMBRE_SUCURSAL>'.$this->getXmlValue($simplexml,'//ticketTienda','nombreSuc').'</NOMBRE_SUCURSAL>
					</SUCURSAL>
					<TICKET>
						<NUMERO_TICKET>'.$this->getXmlValue($simplexml,'//ticketTienda','NumTicket').'</NUMERO_TICKET>
					</TICKET>
					<CAJA>
						<NUMERO_CAJA>'.$this->getXmlValue($simplexml,'//ticketTienda','NumCaja').'</NUMERO_CAJA>
						<NOMBRE_CAJERO>'.$this->getXmlValue($simplexml,'//ticketTienda','NombreCajero').'</NOMBRE_CAJERO>
					</CAJA>
					<METODOS_PAGO>';

		if (isset($metodoPago) && count($metodoPago) > 0) {
			$numMetodoPago = 1;
			foreach($metodoPago as $metodo) {
				$xmlAddenda.='
							<METODO_PAGO Id=\''.$numMetodoPago.'\'>
								<DESCRIPCION>'.$this->getXmlValue($metodo,'','des').'</DESCRIPCION>
							</METODO_PAGO>
				';
				$numMetodoPago++;
			}
		}

		$xmlAddenda.='
					</METODOS_PAGO>';

		$descuentosGlobales = $simplexml->DescuentosGlobales[0];
		if (is_object($descuentosGlobales) && count($descuentosGlobales) > 0) {
			$numDescuento = 1;
			$xmlAddenda.='
					<DESCUENTOSGLOBALES>';
			foreach($descuentosGlobales as $descuento) {
				$xmlAddenda.='
					<DESCUENTO Id=\''.$numDescuento.'\'>
						<IMPORTE>'.$this->getXmlValue($descuento,'','importe').'</IMPORTE>
						<DESCRIPCION>'.$this->getXmlValue($descuento,'','descripcion').'</DESCRIPCION>
					</DESCUENTO>
				';
			}
			$xmlAddenda.='
					</DESCUENTOSGLOBALES>';
		}
		$xmlAddenda.='
				</GENERALES>';
		
		if (count($objComprobante['conceptos']) > 0) {
			$xmlAddenda.='
				<CONCEPTOS>
			';
			$numConcepto = 1;
			foreach($objComprobante['conceptos'] as $concepto) {
				$xmlAddenda.="
					<CONCEPTO Id='".$numConcepto."'>
						<CANTIDAD>".$concepto['cantidad']."</CANTIDAD>
						<UNIDAD>".$concepto['unidad']."</UNIDAD>
						<NIDENTIFICACION>".$concepto['claveUnidad']."</NIDENTIFICACION>
						<DESCRIPCION>".$concepto['descripcion']."</DESCRIPCION>
						<VALORUNITARIO>".$concepto['valorUnitario']."</VALORUNITARIO>
						<IMPORTE>".$concepto['importe']."</IMPORTE>
					</CONCEPTO>
				";
				$numConcepto++;
			}
			$xmlAddenda.='
				</CONCEPTOS>
			';
		}

		$xmlAddenda.='
				<TEXTOS>
					<IMPORTE_LETRA>'.$this->getXmlValue($simplexml,'//ticketTienda','TotalEnLetra').'</IMPORTE_LETRA>
					<COMENTARIOS>1</COMENTARIOS>
				</TEXTOS>
				<DATOS_ADICIONALES_CLUBES>';

		$datosAdicionalesClubes = $simplexml->DatosAdicionalesClubes[0];
		if (is_object($datosAdicionalesClubes) && count($datosAdicionalesClubes) > 0) {
			foreach($datosAdicionalesClubes as $datoAdicional) {
				$xmlAddenda.='
					<DATO_ADICIONAL>
						<DESCRIPCION>'.$this->getXmlValue($datoAdicional,'','descripcion').'</DESCRIPCION>
						<VALOR>'.$this->getXmlValue($datoAdicional,'','valor').'</VALOR>
					</DATO_ADICIONAL>
				';
			}
		}
				$xmlAddenda.='
				</DATOS_ADICIONALES_CLUBES>
			</TMARTI>
		';

		$xmlAddenda = preg_replace("/[\t\r\n]+/", "", $xmlAddenda);

		$objJson['comprobante']['addenda'] = array(
				'items'=>array(
						array(
							'objectType'=>'MartiCustomized',
							'any'=>array($xmlAddenda)
						)
					)
			);
		
		$tipoLogg = 1;
		switch($tipoLogg) {
			case 1:
				$this->getLogger('JSON 32: '.json_encode($objJson));
				break;
			case 2:
				$this->getLogger('JSON 32: '.print_r($objJson,1));
				break;
			case 3:
				$this->getLogger('JSON 32: '.json_encode($objJson));
				$this->getLogger('JSON 32: '.print_r($objJson,1));
				break;
		}

		error_log('Objeto32: '.json_encode($objJson));

		return json_encode($objJson);
	}

	/**
	 * Genera el JSON a partir de ticketTienda para enviar a timbrar
	 * 
	 * @param  string $xml       El contenido de ticketTienda (XML)
	 * @param  int    $idEmpresa El ID de la empresa
	 * @return string            Retorna el JSON ya construido
	 */
	private function crearObjeto33($xml, $idEmpresa) {
		$simplexml = simplexml_load_string($xml);
		$tipoDeComprobante = array('T','P');

		##################################################### Bloque
		//Estructura de raiz



	$objRequest = array(
				'Tipo'=>'JSON',
				'ComprobanteXmlBase64'=>null,
				'ComprobanteJson'=>array(),
				'noControl'=>uniqid(),
				'Version'=>$this->getXmlValue($simplexml,'//ticketTienda','version')
			);


    /*    if (!is_numeric($this->getXmlValue($simplexml,'//ticketTienda','NumTicket'))){

	 $objRequest = array(
				'Tipo'=>'JSON',
				'ComprobanteXmlBase64'=>null,
				'ComprobanteJson'=>null,
				'ComprobanteZIPBase64'=>array(),
				'noControl'=>uniqid(),
				'Version'=>$this->getXmlValue($simplexml,'//ticketTienda','version')
			);

        }else{

		$objRequest = array(
				'Tipo'=>'JSON',
				'ComprobanteXmlBase64'=>null,
				'ComprobanteJson'=>array(),
				'noControl'=>uniqid(),
				'Version'=>$this->getXmlValue($simplexml,'//ticketTienda','version')
			);
	} */
	

		//Validación de "raiz"
		$this->validateAttrib($objRequest['Version'],'192 - No se especifica la versión de CFDI');
		##################################################### Fin Bloque
		
		//Bloque "ComprobanteJSON"
		$objJson = array();
		
		###################################################### Bloque
		//Subloque "CFDI Relacionados"
		$relacionados = $simplexml->CfdiRelacionados;
		if (is_object($relacionados) && count($relacionados) >= 1) {
			$objRelacionados = array(
					'TipoRelacion'=>$this->getXmlValue($simplexml,'//CfdiRelacionados','TipoRelacion'),
					'CfdiRelacionado'=>array()
				);
			foreach($relacionados[0] as $relacion) {
				$tmpUUID = $this->getXmlValue($relacion,'','UUID');
				$this->validateAttrib($tmpUUID,'207 - Se debe especificar un UUID');
				if (strlen($tmpUUID) > 36)
					throw new Exception('208 - El UUID ('.$tmpUUID.') debe ser máximo de 36 caracteres');
					
				//array_push($objTmpRela,array('CfdiRelacionado'=>array('UUID'=>$tmpUUID)));
				//$objRelacionados['CfdiRelacionados'][]['CfdiRelacionado']=array('UUID'=>$tmpUUID);
				array_push($objRelacionados['CfdiRelacionado'],array('UUID'=>$tmpUUID));
			}

			//$objRelacionados = array('CfdiRelacionados'=>$objRelacionados);
			$objJson['CfdiRelacionados'] = $objRelacionados;

			$this->validateAttrib($objRelacionados['TipoRelacion'],'209 - Se debe especificar el tipo de relación');
			$tmpRelacion = (strpos($objRelacionados['TipoRelacion'],'0') === 0) ? substr($objRelacionados['TipoRelacion'],1) : $objRelacionados['TipoRelacion'];

			//$tiposDeRelacion = (new pTipoRela())->check($tmpRelacion);
			$tiposDeRelacion = (new pTipoRela())->check($objRelacionados['TipoRelacion']);
			if (count($tiposDeRelacion) == 0)
				throw new Exception('210 - El tipo de relación ('.$objRelacionados['CfdiRelacionados']['TipoRelacion'].') no es válido');
		}
		###################################################### Fin Bloque
		
		###################################################### Bloque
		//Subloque "Emisor"
		$objJson['Emisor'] = array(
				'Rfc'=>$this->getXmlValue($simplexml,'//Emisor','rfc'),
				'Nombre'=>$this->getXmlValue($simplexml,'//Emisor','nombre'),
				'RegimenFiscal'=>$this->getXmlValue($simplexml,'//RegimenFiscal','Regimen')
			);

		/*//Validaciones del Emisor
		//Validacion del RFC
		$this->validateAttrib($objJson['Emisor']['Rfc'],'211 - Se debe indicar el RFC del Emisor');
		if (strlen($objJson['Emisor']['Rfc']) > 12)
			throw new Exception('212 - El RFC ('.$objJson['Emisor']['Rfc'].') del Emisor debe ser máximo de 12 caracteres');

		//Validacion del Nombre
		$this->validateAttrib($objJson['Emisor']['Nombre'],'213 - Se debe especificar el nombre del Emisor');
		if (strlen($objJson['Emisor']['Nombre']) > 250)
			throw new Exception('214 - El nombre no debe ser máximo de 250 caracteres');

		//Validacion del regimen fiscal
		$this->validateAttrib($objJson['Emisor']['RegimenFiscal'],'215 - Se debe indicar el regimen fiscal del Emisor');
		$regimenFiscal = (new pRegFiscal())->check($objJson['Emisor']['RegimenFiscal']);
		if (count($regimenFiscal) == 0)
			throw new Exception('216 - El regimen fiscal ('.$objJson['Emisor']['RegimenFiscal'].') no es válido');*/
		
		##################################################### Fin Bloque
		
		###################################################### Bloque
		//Subloque "Receptor"
		$objJson['Receptor'] = array(
				'Rfc'=>$this->getXmlValue($simplexml,'//Receptor','rfc'),
				'Nombre'=>$this->getXmlValue($simplexml,'//Receptor','nombre'),
				'ResidenciaFiscal'=>$this->getXmlValue($simplexml,'//Receptor','ResidenciaFiscal',null),
				'NumRegIdTrib'=>$this->getXmlValue($simplexml,'//Receptor','NumRegIdTrib',null),
				'UsoCFDI'=>$this->getXmlValue($simplexml,'//Receptor','UsoCFDI')
			);

		/*//Validaciones del Receptor
		//Validacion del RFC
		$this->validateAttrib($objJson['Receptor']['Rfc'],'217 - Se debe indicar el RFC del Receptor');
		if (strlen($objJson['Receptor']['Rfc']) > 13)
			throw new Exception('218 - El RFC ('.$objJson['Emisor']['Rfc'].') del Receptor debe ser máximo de 13 caracteres');

		//Validacion del Nombre
		$this->validateAttrib($objJson['Receptor']['Nombre'],'219 - Se debe especificar el nombre del Receptor');
		if (strlen($objJson['Receptor']['Nombre']) > 250)
			throw new Exception('220 - El nombre no debe ser máximo de 250 caracteres');

		//Validación del uso de CFDI
		$this->validateAttrib($objJson['Receptor']['UsoCFDI'],'224 - Se debe especificar el uso de CFDI');
		$usoCfdi=(new pUsoCfdi())->check($objJson['Receptor']['UsoCFDI']);
		if (count($usoCfdi) == 0)
			throw new Exception('225 - El uso de CFDI ('.$objJson['Receptor']['UsoCFDI'].') no es válido');*/
		
		//Validación de UsoCFDI para Clubes (G02 y G03), para tiendas es G01 y G02
		$usoCFDIValidos = array('G01','G02','G03','I02','I03','I04','I08','P01');
  		if (!in_array($objJson['Receptor']['UsoCFDI'], $usoCFDIValidos))
			throw new Exception('225 - El uso de CFDI no es válido para Tiendas '.$objJson['Receptor']['UsoCFDI']);
		##################################################### Fin Bloque
		
		###################################################### Bloque
		//Subloque "Conceptos"
		$conceptos = $simplexml->Conceptos[0];
		//Totales
		$subTotal = 0;
		$totalImpuestosTrasladados = 0;
		$totalImpuestosRetenidos = 0;
		$objConceptos = array('Conceptos'=>array());

		if (is_object($conceptos) && count($conceptos) >= 1) {
			foreach($conceptos as $concepto) {
				$impuestos = $concepto->Impuestos;
				$objImpuestos = array();

				if (count($impuestos) > 0) {
					$objImpuestos = array();
					foreach($impuestos as $impuesto) {
						//Traslados
						if (count($impuestos->Traslados) > 0) {
							$objTmpImpuestosTraslados = array(
									'Base'=>$this->getXmlValue($impuestos->Traslados->Traslado,'','Base'),
									'Impuesto'=>$this->getXmlValue($impuestos->Traslados->Traslado,'','Impuesto'),
									'TipoFactor'=>$this->getXmlValue($impuestos->Traslados->Traslado,'','TipoFactor'),
									'TasaOCuota'=>$this->getXmlValue($impuestos->Traslados->Traslado,'','TasaOCuota'),
									'Importe'=>number_format($this->getXmlValue($impuestos->Traslados->Traslado,'','Importe','','float'),2,'.',''),
									'BaseSpecified'=>true,
									'ImporteSpecified'=>true
								);
							$totalImpuestosTrasladados += $objTmpImpuestosTraslados['Importe'];
							if (empty($objImpuestos['Traslados']))
								$objImpuestos['Traslados'] = array();
							array_push($objImpuestos['Traslados'],$objTmpImpuestosTraslados);
						}

						//Retenciones
						if (count($impuestos->Retenciones) > 0) {
							$objTmpImpuestosRetenciones = array(
									'Base'=>$this->getXmlValue($impuestos->Retenciones->Retencion,'','Base'),
									'Impuesto'=>$this->getXmlValue($impuestos->Retenciones->Retencion,'','Impuesto'),
									'TipoFactor'=>$this->getXmlValue($impuestos->Retenciones->Retencion,'','TipoFactor'),
									'TasaOCuota'=>$this->getXmlValue($impuestos->Retenciones->Retencion,'','TasaOCuota'),
									'Importe'=>number_format($this->getXmlValue($impuestos->Retenciones->Retencion,'','Importe','','float'),2,'.',''),
									'BaseSpecified'=>true,
									'ImporteSpecified'=>true
								);
							$totalImpuestosRetenidos += $$objTmpImpuestosRetenciones['Importe'];
							if (empty($objImpuestos['Retenciones']))
								$objImpuestos['Retenciones'] = array();
							array_push($objImpuestos['Retenciones'],$objTmpImpuestosRetenciones);
						}
					}
				}

				//$descuento = !empty($concepto->attributes()->descuento[0]) ? $concepto->attributes()->descuento[0] : '0.0';
				$descuento = '0.00';
				if (isset($concepto->attributes()->descuento))
					$descuento = (float)$concepto->attributes()->descuento;
				else if (isset($concepto->attributes()->Descuento))
					$descuento = (float)$concepto->attributes->Descuento;

               $tc= $this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante');
            
               $this->logger->info('Tipo de comprobante:'.$tc);
			   
			   $objConcepto = array(
						'InformacionAduanera'=>null,
						'CuentaPredia'=>null,
						'ComplementoConcepto'=>null,
						'Parte'=>null,
						'ClaveProdServ'=>$this->getXmlValue($concepto,'','ClaveProdServ'),
						'NoIdentificacion'=>$this->getXmlValue($concepto,'','noIdentificacion'),
						'Cantidad'=>$this->getXmlValue($concepto,'','cantidad'), //verificación de decimales
						'ClaveUnidad'=>$this->getXmlValue($concepto,'','ClaveUnidad'),
						'Unidad'=>$this->getXmlValue($concepto,'','unidad'),
						'Descripcion'=>($this->getXmlValue($concepto,'','descripcion')),
       	      
                       'ValorUnitario' => $tc=='P' ? number_format($this->getXmlValue($concepto,'','valorUnitario'),0,'.','') : number_format($this->getXmlValue($concepto,'','valorUnitario'),2,'.',''),

                        'Importe' => $tc=='P' ? number_format((float)$concepto->attributes()->importe[0],0,'.','')
                                     : number_format((float)$concepto->attributes()->importe[0],2,'.',''),					
						'Descuento'=>number_format($descuento,2,'.',''),
						'DescuentoSpecified'=>true,
						'Impuestos'=>$objImpuestos
					);

				if ($objConcepto['NoIdentificacion'] == '')
    			 unset($objConcepto['NoIdentificacion']);
				$subTotal += floatval((float)$concepto->attributes()->importe[0]);

				if (in_array($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'),$tipoDeComprobante)) {
					unset($objConcepto['Descuento']);
					unset($objConcepto['DescuentoSpecified']);
					unset($objConcepto['Impuestos']);
				}

				//Validación del subloque "Conceptos"
				//Validación ClaveProdServ
				/*
				$this->validateAttrib($objConcepto['ClaveProdServ'],'226 - Debes indicar la clave del producto/servicio');
				$claveProdServ = (new pClaPRodServ())->check($objConcepto['ClaveProdServ']);
				if (count($claveProdServ) == 0)
					throw new Exception('227 - La clave de producto/serivico no es válida');

				//Validación cantidad
				$this->validateAttrib($objConcepto['Cantidad'],'227 - Debes indicar la cantidad');

				//Validación clave unidad
				$this->validateAttrib($objConcepto['ClaveUnidad'],'228 - Debes indicar la clave unidad');
				$claveUnidad = (new pClaveUnidad())->check($objConcepto['ClaveUnidad']);
				if (count($claveUnidad) == 0)
					throw new Exception('229 - La clave undicada ('.$objConcepto['ClaveUnidad'].') no es válida');	

				//Validación unidad
				$this->validateAttrib($objConcepto['Unidad'],'230 - Debes indicar la unidad a usar');

				//Validación descripción
				$this->validateAttrib($objConcepto['Descripcion'],'231 - Debes indicar la descripción');

				//Validación valor unitario
				$this->validateAttrib($objConcepto['ValorUnitario'],'232 - Debes indicar el valor unitario');

				//Validación importe
				$this->validateAttrib($objConcepto['Importe'],'233 - Debes indicar el importe');
				*/
				array_push($objConceptos['Conceptos'],$objConcepto);
			}

			$objJson = array_merge($objJson,$objConceptos);
		}
		##################################################### Fin Bloque
		
		###################################################### Bloque
		//Subloque "Impuestos"
		$impuestos = $simplexml->Impuestos;
		$objImpuestos = array('Impuestos'=>array());

		//Impuestos->Retenciones
		if (isset($impuestos->Retenciones)) {
			$objImpuestos['Impuestos']['Retenciones'] = array();

			foreach($impuestos->Retenciones as $retencion) {
				$objImpuestosRetencion = array(
						'Impuesto'=>$this->getXmlValue($retencion->Retencion,'','impuesto'),
						'TipoFactor'=>$this->getXmlValue($retencion->Retencion,'','TipoFactor'),
						'TasaOCuota'=>$this->getXmlValue($retencion->Retencion,'','TasaOCuota'),
						'Importe'=>number_format($this->getXmlValue($retencion->Retencion,'','Importe','','float'),2,'.','')
					);
				array_push($objImpuestos['Impuestos']['Retenciones'], $objImpuestosRetencion);
			}
		}

		//Impuestos->Traslados
		if (isset($impuestos->Traslados)) {
			$objImpuestos['Impuestos']['Traslados'] = array();
			
			foreach($impuestos->Traslados as $traslado) {
				$objImpuestosTraslado = array(
						'Impuesto'=>$this->getXmlValue($traslado->Traslado,'','impuesto'),
						'TipoFactor'=>$this->getXmlValue($traslado->Traslado,'','TipoFactor'),
						'TasaOCuota'=>$this->getXmlValue($traslado->Traslado,'','TasaOCuota'),
						'Importe'=>number_format($this->getXmlValue($traslado->Traslado,'','importe','','float'),2,'.','')
					);
				array_push($objImpuestos['Impuestos']['Traslados'],$objImpuestosTraslado);
			}
		}

		if (isset($objImpuestos['Impuestos']['Retenciones']) && !empty($objImpuestos['Impuestos']['Retenciones'])) {
			$objImpuestos['Impuestos']['TotalImpuestosRetenidos'] = number_format($this->getXmlValue($impuestos->Retenciones,'','TotalImpuestosRetenidos','0'),2,'.','');
			$objImpuestos['Impuestos']['TotalImpuestosRetenidosSpecified'] = true;
		}
		
		if (isset($objImpuestos['Impuestos']['Traslados']) && !empty($objImpuestos['Impuestos']['Traslados'])) {
			$objImpuestos['Impuestos']['TotalImpuestosTrasladados'] = number_format($this->getXmlValue($impuestos->Traslados,'','TotalImpuestosTrasladados','0'),2,'.','');
			$objImpuestos['Impuestos']['TotalImpuestosTrasladadosSpecified'] = true;
		}

		if (count($objImpuestos['Impuestos']) >= 1)
			$objJson = array_merge($objJson,$objImpuestos);
		##################################################### Fin Bloque
		
		###################################################### Bloque
		//Bloque Complemento
		$objComplementos = array();
		//$objComplementos['objectType']='ComplementoPagos';
		//Subloque Pagos
		$pagos = $simplexml->Pagos;
		$objTmpPagos = array();
		$msgError = array();
		$numPago = 1;
		foreach($pagos as $pago) {
			if ($this->getXmlValue($pago->Pago->DoctoRelacionado,'','IdDocumento','') == '')
				array_push($msgError,'No está definido el atributo IdDocumento en el pago #'.$numPago);

			if ($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MonedaDR','') == '')
				array_push($msgError,'No está definido el atributo MonedaDR en el pago #'.$numPago);

			if ($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR','') == '')
				array_push($msgError,'No está definido el atributo MetodoDePagoDR en el pago #'.$numPago);

			$metodosDePagoDRCo = array('PIP','PPD');

			if (in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','NumParcialidad','') == '')
				array_push($msgError,'Debes definir el atributo NumParcialidad al Metodo de pago ser PIP o PPD en el pago #'.$numPago);

			if (in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoAnt','') == '')
				array_push($msgError,'Debes definir el atributo ImpSaldoAnt al Metodo de pago ser PIP o PPD en el pago #'.$numPago);

			if (in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpPagado','') == '')
				array_push($msgError,'Debes definir el atributo ImpPagado al Metodo de pago ser PIP o PPD en el pago #'.$numPago);

			if (in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoInsoluto','') == '')
				array_push($msgError,'Debes definir el atributo ImpSaldoInsoluto al Metodo de pago ser PIP o PPD en el pago #'.$numPago);

			$objDoctoRela = array(array(
					//obligatorio
					'IdDocumento'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','IdDocumento'),
					'Serie'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','Serie'),
					'Folio'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','Folio'),
					//Obligatorio
					'MonedaDR'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','MonedaDR'),
					//Condicional a moneda distinta a MXN
					'TipoCambio'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','TipoCambioDR'),
					//Obligatorio
					'MetodoDePagoDR'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'),
					//Condicional a MEtodoDePagoDR es PIP o PPD o quitar nodo
					'NumParcialidad'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','NumParcialidad'),
					//Condicional a MEtodoDePagoDR es PIP o PPD
					'ImpSaldoAnt'=>number_format($this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoAnt'),2,'.',''),
					//Condicional a MEtodoDePagoDR es PIP o PPD
					'ImpPagado'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpPagado'),
					//Condicional a MEtodoDePagoDR es PIP o PPD
					'ImpSaldoInsoluto'=>$this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoInsoluto')
				));

			if(empty($objDoctoRela[0]['Serie']))
				unset($objDoctoRela[0]['Serie']);

			if(empty($objDoctoRela[0]['Folio']))
				unset($objDoctoRela[0]['Folio']);

			if (!in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','NumParcialidad','') == '')
				unset($objDoctoRela['NumParcialidad']);

			if (!in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoAnt','') == '')
				unset($objDoctoRela['ImpSaldoAnt']);

			if (!in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpPagado','') == '')
				unset($objDoctoRela['ImpPagado']);

			if (!in_array($this->getXmlValue($pago->Pago->DoctoRelacionado,'','MetodoDePagoDR'), $metodosDePagoDRCo) && $this->getXmlValue($pago->Pago->DoctoRelacionado,'','ImpSaldoInsoluto','') == '')
				unset($objDoctoRela['ImpSaldoInsoluto']);

			$tmpImpuestos = $pago->Pago->Impuestos;
			$objPagosImpuestos = array();
			foreach($tmpImpuestos as $pagoImpuesto) {
				if (count($pagoImpuesto->Traslados) > 0) {
					$objPagosImpuestos['Traslados'][] = array(
							'Impuesto'=>$this->getXmlValue($pagoImpuesto->Traslados->Traslado,'','Impuesto'),
							'TipoFactor'=>$this->getXmlValue($pagoImpuesto->Traslados->Traslado,'','TipoFactor'),
							'TasaOCuota'=>$this->getXmlValue($pagoImpuesto->Traslados->Traslado,'','TasaOCuota'),
							'Importe'=>number_format($this->getXmlValue($pagoImpuesto->Traslados->Traslado,'','Importe'),2,'.','')
						);
					$objPagosImpuestos['Traslados']['TotalImpuestosTrasladados'] = $this->getXmlValue($pagoImpuesto->Traslados,'','TotalImpuestosTrasladados');
				}

				if (count($pagoImpuesto->Retenciones) > 0) {
					$objPagosImpuestos['Retenciones'][] = array(
							'Impuesto'=>$this->getXmlValue($pagoImpuesto->Retenciones->Retencion,'','Impuesto'),
							'TipoFactor'=>$this->getXmlValue($pagoImpuesto->Retenciones->Retencion,'','TipoFactor'),
							'TasaOCuota'=>$this->getXmlValue($pagoImpuesto->Retenciones->Retencion,'','TasaOCuota'),
							'Importe'=>number_format($this->getXmlValue($pagoImpuesto->Retenciones->Retencion,'','Importe'),2,'.','')
						);
					$objPagosImpuestos['Retenciones']['TotalImpuestosRetenciones'] = $this->getXmlValue($pagoImpuesto->Retenciones,'','TotalImpuestosRetenciones');
				}
			}

			$objPago = array(
					'FechaPago'=>$this->getXmlValue($pago->Pago,'','FechaPago'),
					'FormaDePagoP'=>$this->getXmlValue($pago->Pago,'','FormaDePagoP'),
					'MonedaP'=>$this->getXmlValue($pago->Pago,'','MonedaP'),
					'TipoCambio'=>$this->getXmlValue($pago->Pago,'','TipoCambioP'),
					'Monto'=>$this->getXmlValue($pago->Pago,'','Monto'),
					'NumOperacion'=>$this->getXmlValue($pago->Pago,'','NumOperacion'),
					// 'RFCEmisorCtaOrd'=>$this->getXmlValue($pago->Pago,'','RfcEmisorCtaOrd'),
					'RFCEmisorCtaOrd'=>(string)$pago->Pago[0]->attributes()->RfcEmisorCtaOrd,
					'NomBancoOrdExt'=>$this->getXmlValue($pago->Pago,'','NomBancoOrdExt'),
					'CtaOrdenante'=>$this->getXmlValue($pago->Pago,'','CtaOrdenante'),
					'RFCEmisorCtaBen'=>(string)$pago->Pago[0]->attributes()->RfcEmisorCtaBen,
					'CtaBeneficiario'=>$this->getXmlValue($pago->Pago,'','CtaBeneficiario'),
					'TipoCadPago'=>$this->getXmlValue($pago->Pago,'','TipoCadPago'),
					'CertPago'=>$this->getXmlValue($pago->Pago,'','CertPago'),
					'CadPago'=>$this->getXmlValue($pago->Pago,'','CadPago'),
					'SelloPago'=>$this->getXmlValue($pago->Pago,'','SelloPago'),
					'DoctoRelacionado'=>$objDoctoRela
				);

			if (empty($objPago['TipoCadPago'])) {
				unset($objPago['TipoCadPago']);
			}

			if (empty($objPago['CertPago'])) {
				unset($objPago['CertPago']);
			}

			if (empty($objPago['CadPago'])) {
				unset($objPago['CadPago']);
			}

			if (empty($objPago['SelloPago'])) {
				unset($objPago['SelloPago']);
			}

			if (empty($objPago['RFCEmisorCtaOrd'])) {
				unset($objPago['RFCEmisorCtaOrd']);
			}

			if (empty($objPago['NomBancoOrdExt'])) {
				unset($objPago['NomBancoOrdExt']);
			}

			if (empty($objPago['CtaOrdenante'])) {
				unset($objPago['CtaOrdenante']);
			}

			if (empty($objPago['RFCEmisorCtaBen'])) {
				unset($objPago['RFCEmisorCtaBen']);
			}

			if (empty($objPago['CtaBeneficiario'])) {
				unset($objPago['CtaBeneficiario']);
			}

			// if (count($objPagosImpuestos) >= 1)
				// $objPago['Impuestos']=$objPagosImpuestos;

			array_push($objTmpPagos,$objPago);
			$numPago++;
		}
		$objComplementos = array(
				'Items'=>array(array(
						'ObjectType'=>'Pagos',
						'Version'=>'1.0',
						'Pago'=>$objTmpPagos
					))
			);
		##################################################### Fin Bloque
		
		###################################################### Bloque
		//Bloque Addenda
		if ($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante') != 'P') {
			$this->noTicket = $this->getXmlValue($simplexml,'//ticketTienda','NumTicket');
			$xmlAddenda = '
				<TMARTI>
					<GENERALES>
						<TIPODOCUMENTO>'.$this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante').'</TIPODOCUMENTO>
						<SUCURSAL>
							<CODIGO_SUCURSAL>'.$this->getXmlValue($simplexml,'//ticketTienda','CodSuc').'</CODIGO_SUCURSAL>
							<NOMBRE_SUCURSAL>'.$this->getXmlValue($simplexml,'//ticketTienda','nombreSuc').'</NOMBRE_SUCURSAL>
						</SUCURSAL>
						<TICKET>
							<NUMERO_TICKET>'.$this->getXmlValue($simplexml,'//ticketTienda','NumTicket').'</NUMERO_TICKET>
						</TICKET>
						<TEXTOPIE>'.$this->getXmlValue($simplexml,'//ticketTienda','TextoPie').'</TEXTOPIE>
						<CAJA>
							<NUMERO_CAJA>'.$this->getXmlValue($simplexml,'//ticketTienda','NumCaja').'</NUMERO_CAJA>
							<NOMBRE_CAJERO>'.$this->getXmlValue($simplexml,'//ticketTienda','NombreCajero').'</NOMBRE_CAJERO>
						</CAJA>';

			if (in_array($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'), $tipoDeComprobante)) {
				$xmlAddenda.='
							<METODOS_PAGO>
								<METODO_PAGO>'.$this->getXmlValue($simplexml,'//ticketTienda','MetodoPago').'</METODO_PAGO>
							</METODOS_PAGO>';
			}

			$xmlAddenda.='
						<REFERENCIA_NOTA_CREDITO>';

			//Subloque Referencia_Nota_Credito (CFDI Relacionados) 01
			if ($this->getXmlValue($simplexml,'//CfdiRelacionados','TipoRelacion') == '01') {
				$xmlAddenda .= '
							<RELACIONADOS>';
				foreach($objRelacionados as $tmpRelacionado) {
					$xmlAddenda .= '
								<UUID>'.$tmpRelacionado['CfdiRelacionado']['UUID'].'</UUID>';
				}
				$xmlAddenda .= '
							</RELACIONADOS>';
			}
			
			$xmlAddenda.= '
						</REFERENCIA_NOTA_CREDITO>';

			//Subloque DescuentosGlobales
			$descuentosGloables = $simplexml->DescuentosGlobales[0];
			$xmlAddenda.='
						<DESCUENTOSGLOBALES>';
			if (!empty($descuentosGloables) && count($descuentosGloables) > 0) {
				
				foreach($descuentosGloables as $descuento) {
					$xmlAddenda.='
							<DESCUENTO DESCRIPCION=\''.$descuento->attributes()->descripcion[0].'\' IMPORTE=\''.$descuento->attributes()->importe[0].'\'/>';
				}
			}
			$xmlAddenda.='
						</DESCUENTOSGLOBALES>
					</GENERALES>';

			$xmlAddenda.='
					<CONCEPTOS>';
			$tmpId = 1;	
			foreach($objConceptos['Conceptos'] as $concepto) {
				$xmlAddenda.="
						<CONCEPTO Id='".$tmpId."'>
							<CANTIDAD>".$concepto['Cantidad']."</CANTIDAD>
							<UNIDAD>".$concepto['Unidad']."</UNIDAD>
							<NIDENTIFICACION>".$concepto['NoIdentificacion']."</NIDENTIFICACION>
							<DESCRIPCION>".ComprobanteFiscal::depuraValoresXML($concepto['Descripcion'])."</DESCRIPCION>
							<VALORUNITARIO>".$concepto['ValorUnitario']."</VALORUNITARIO>
							<IMPORTE>".$concepto['Importe']."</IMPORTE>
						</CONCEPTO>
							";
				$tmpId++;
			}

			$xmlAddenda.='
					</CONCEPTOS>
					<TEXTOS>
						<IMPORTE_LETRA>'.$this->getXmlValue($simplexml,'//ticketTienda','TotalEnLetra').'</IMPORTE_LETRA>
					</TEXTOS>
					<DATOS_ADICIONALES_CLUBES>';

			$datosAdicionales = $simplexml->DatosAdicionalesClubes;
			if (count($datosAdicionales) > 0) {
				foreach($datosAdicionales->DatoAdicional as $datoAdicional) {
					$xmlAddenda.='
						<DATO_ADICIONAL>
							<DESCRIPCION>'.(string)$datoAdicional[0]['descripcion'].'</DESCRIPCION>
							<VALOR>'.(string)$datoAdicional[0]['valor'].'</VALOR>
						</DATO_ADICIONAL>
					';
	 			}
			}

			$xmlAddenda.='
					</DATOS_ADICIONALES_CLUBES>
				</TMARTI>
			';

			//Quitamos los espacios, tabs, saltos de linea y de carro
			$xmlAddenda = preg_replace("/[\t\r\n]+/", "", $xmlAddenda);
		}else {
			$xmlAddenda = '';
		}

		//Agregamos los fix necesarios de los reemplasos anteriores
		// $xmlAddenda = str_replace('CONCEPTOId=', 'CONCEPTO Id = ', $xmlAddenda);
		// $xmlAddenda = str_replace('DESCUENTODESCRIPCION','DESCUENTO DESCRIPCION',$xmlAddenda);
		// $xmlAddenda = str_replace("tos'IMPORTE", "tos' IMPORTE", $xmlAddenda);
		##################################################### Fin Bloque

		###################################################### Bloque
		//Continuación bloque comprobanteJSON
		
		//Obtenemos folio desde cSerie
		$tmpSucursal = (new cSucursal())->consultar([
			'sucursal'	=> $this->getXmlValue($simplexml,'//ticketTienda','CodSuc')	
		]);

			error_log("mensaje Sucursal: ".print_r($tmpSucursal,1));

	

		$moduloCSerie = new cSerie();
		$tmpTipoComprobante = $this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante');
		$tmpTipoComprobante = ($tmpTipoComprobante == 'P' ? 'I' : $this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'));
		$tmpFolio = $moduloCSerie->consultar([
			"sucursal"		=> $tmpSucursal[0]['id'],
			"tipoComprobante"	=> $tmpTipoComprobante,
			"activo"		=> 1
		]);
		error_log("mensaje tmpFolio: ".print_r($tmpFolio,1));
		// if(empty($tmpFolio[0]['id']))
		// {
		// 	$tmpFolio[0]['id']=0;
		// }
		// $this->logger->info(print_r("mensaje de folio en serie: ".$tmpFolio[0]['id']));
		$moduloCSerie->actualizarFolio($tmpFolio[0]['id']);

		$tmpCertificado = (new cCertificado())->consultar([
				'id_cempresa'=>$tmpSucursal[0]['empresa']
			]);

		$objTmpJson = array(
				//'Complemento'=>($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante') == 'P' ? array('Items'=>array(array('objectType'=>'ComplementoPagos','any'=>array($objComplementos)))) : null),
				'Complemento'=>($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante') == 'P') ? $objComplementos : null,
				//'Complemento'=>null,
				'Addenda'=>array('Items'=>array(array('objectType'=>'MartiCustomized','any'=>array($xmlAddenda)))),
				//'Addenda'=>null,
				'Version'=>$this->getXmlValue($simplexml,'//ticketTienda','version'),
				'Serie'=>$this->getXmlValue($simplexml,'//ticketTienda','serie'),
				'Folio'=>$tmpFolio[0]['folioActial'],
				'Fecha'=>$this->getXmlValue($simplexml,'//ticketTienda','fecha'),
				'Sello'=>null,
				//'NoCertificado'=>$this->getXmlValue($simplexml,'//ticketTienda','noCertificado'),
				'NoCertificado'=>$tmpCertificado[0]['ccertificado'],
				'Certificado'=>$this->getXmlValue($simplexml,'//ticketTienda','certificado'),
				'SubTotal'=>number_format($this->getXmlValue($simplexml,'//ticketTienda','subTotal'),2,'.',''),
				'Descuento'=>number_format($this->getXmlValue($simplexml,'//ticketTienda','descuento'),2,'.',''),
				'DescuentoSpecified'=>true,
				'Moneda'=>'MXN',
				'TipoCambio'=>'1.00',
				'TipoCambioSpecified'=>false,
				'Total'=>number_format($this->getXmlValue($simplexml,'//ticketTienda','total','','float'),2,'.',''),
				'TipoDeComprobante'=>$this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'),
				'LugarExpedicion'=>$this->getXmlValue($simplexml,'//ticketTienda','LugarExpedicion'),
				'MetodoPago'=>$this->getXmlValue($simplexml,'//ticketTienda','MetodoPago'),
				'FormaPago'=>$this->getXmlValue($simplexml,'//ticketTienda','formaDePago'),
				'CondicionesDePago'=>$this->getXmlValue($simplexml,'//ticketTienda','CondicionesDePago'),
				'Confirmacion'=>null,
			);

		if (in_array($objTmpJson['TipoDeComprobante'],$tipoDeComprobante)) {
			unset($objTmpJson['Addenda']);
			unset($objTmpJson['MetodoPago']);
			unset($objTmpJson['FormaPago']);
			unset($objTmpJson['CondicionesDePago']);
		}
		if (empty($objTmpJson['CondicionesDePago'])) {
			unset($objTmpJson['CondicionesDePago']);
		}
		/*
		//Validaciones
		$this->validateAttrib($objTmpJson['Serie'],'193 - No se especifica la serie','//ticketTienda');

		$this->validateAttrib($objTmpJson['NoCertificado'],'194 - El número de certificado es requerido');

		if (empty($tmpFolio)) {
			throw new Exception('195 - No hay serie para la sucursal '.$tmpSucursal[0]['clave'].' con el tipo de comprobante '.$this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante'));
		}

		//Validación de fecha
		$this->validateAttrib($objTmpJson['Fecha'],'196 - No se especifico la fecha');

		$sucursalesCancun = explode(',',self::SUCURSALES_CANCUN);

		if (in_array($this->getXmlValue($simplexml,'//ticketTienda','CodSuc'), $sucursalesCancun)) {
			$fecha = new DateTime($this->getXmlValue($simplexml,'//ticketTienda','fecha'));
			$fecha->sub(new DateInterval('PT1H'));
			$simplexml['fecha'] = $fecha->format('d-m-Y H:i:s');
		}

		$fecha = date_create($this->getXmlValue($simplexml,'//ticketTienda','fecha'));
		$simplexml['fecha'] = date_format($fecha,'Y-m-d\TH:i:s');

		//Validación Moneda
		//$this->validateAttrib($objTmpJson['Moneda'],'198 - Se debe especificar la moneda a usar');

		//Validacion TipoCambio
		if ($this->getXmlValue($simplexml,'//ticketTienda','tipoCambio','MXN') != 'MXN') {
			$this->validateAttrib($objTmpJson['TipoCambio'],'197 - El tipo de cambio ('.$objTmpJson['TipoCambio'].') es diferente a MXN, se debe especificar el tipo de cambio');
		}

		//Validación forma de pago
		$this->validateAttrib($objTmpJson['FormaPago'],'199 - La forma de pago es requerida');
		$formasDePago = array('01','02','03','04','05','06','08','12','13','14','15','17','23','24','25','26','27','28','29','99');
		if (!in_array($objTmpJson['FormaPago'], $formasDePago))
			throw new Exception('200 - El tipo de pago ('.$objTmpJson['FormaPago'].') no es válido');

		//Validación importe total
		$this->validateAttrib($objTmpJson['Total'],'201 - Se debe especificar el total');

		//Validación del tipo de comprobante
		$this->validateAttrib($objTmpJson['TipoDeComprobante'],'202 - Se debe especificar el tipo de comprobante');
		$tiposDeComprobante = array('I','E','T','N','P');
		if (!in_array($objTmpJson['TipoDeComprobante'], $tiposDeComprobante))
			throw new Exception('203 - El tipo de comprobante ('.$objTmpJson['TipoDeComprobante'].') no es válido');

		//Validación metodo de pago
		$this->validateAttrib($objTmpJson['MetodoPago'],'204 - Se debe indicar el metodo de pago');
		$metodosDePago = array('PUE','PIP','PPD');
		if (!in_array($objTmpJson['MetodoPago'], $metodosDePago))
			throw new Exception('205 - El metodo de pago ('.$objTmpJson['MetodoPago'].') indicado no es válido');
		
		//Validación lugar de expedición
		$this->validateAttrib($objTmpJson['LugarExpedicion'],'206 - Se debe especificar el lugar de expedición');
		*/
	
		$objJson = array_merge($objJson,$objTmpJson);

		$objRequest['ComprobanteJson'] = $objJson;
		
		$tipoLogg = 3;
		switch($tipoLogg) {
			case 1:
				$this->getLogger('JSON 33: '.json_encode($objRequest));
				break;
			case 2:
				$this->getLogger('JSON 33: '.print_r($objRequest,1));
				break;
			case 3:
				$this->getLogger('JSON 33: '.json_encode($objRequest));
				$this->getLogger('JSON 33: '.print_r($objRequest,1));
				break;
			}

		if ($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante') != 'P') {
			//Realizamos las validaciones de Marti
			$this->validacionesMarti($objRequest);
		}else {
			unset($objRequest['ComprobanteJson']['Conceptos'][0]['Unidad']);
			unset($objRequest['ComprobanteJson']['Descuento']);
			unset($objRequest['ComprobanteJson']['DescuentoSpecified']);
			unset($objRequest['ComprobanteJson']['TipoCambio']);
			unset($objRequest['ComprobanteJson']['TipoCambioSpecified']);
			$objRequest['ComprobanteJson']['Moneda'] = 'XXX';
			$objRequest['ComprobanteJson']['Subtotal'] = 0;
			$objRequest['ComprobanteJson']['Total'] = 0;
		}

		if ($this->getXmlValue($simplexml,'//ticketTienda','tipoDeComprobante') != 'P') {
			return json_encode($objRequest);
		}else {
			if (count($msgError) == 0)
				return json_encode($objRequest);
			else
				return json_encode($msgError);
		}
	}

	/**
	 * Reliza las validaciones requeridas por Marti
	 * 
	 * @param  array $object Objeto con todo la información del JSON generado para CFDI 3.3
	 * @return void
	 */
	private function validacionesMarti($object) {
		$comprobanteJson = $object['ComprobanteJson'];
		
		################
		#
		#   Validación Raiz
		#
		################
		//Validación de Serie
		$this->validateAttrib($comprobanteJson['Serie'],'234 - Debes indicar el tipo de serie');

		//Validación de Folio
		//$this->validateAttrib($comprobanteJson['Folio'],'235 - El folio es requerido');

		//Validación de FormaDePago
		$tipoDePago = array('P','T','N');
		if (in_array($comprobanteJson['tipoDeComprobante'], $tipoDePago)) {
			$this->validateAttrib($comprobanteJson['FormaPago'],'236 - La forma de pago es requerida');
			$this->validateAttrib($comprobanteJson['MetodoPago'],'237 - Al indicar la forma de pago el metodo de pago es requerido');
		}

		//Validacion de MetodoPago
		

		################
		#
		#   Validación Relacionados
		#
		################
		//Validación de Relacionados
		$this->getLogger('Validando TipoDeComprobante: '.$comprobanteJson['TipoDeComprobante']);
		if ($comprobanteJson['TipoDeComprobante'] == 'P') {
			foreach($object['CfdiRelacionados'] as $relacionados) {
				$this->getLogger('CFDI relacionado: '.$relacionados[0]['CfdiRelacionado']['UUID']);
			}
		}

		if (!in_array($comprobanteJson['TipoDeComprobante'], $this->tipoComprobante)) {
			################
			#
			#   Validación Emisor
			#
			################
			//Validación del nombre
			$this->validateAttrib($comprobanteJson['Emisor']['Nombre'],'239 - El nombre del emisor es requerido');

			################
			#
			#   Validación Receptor
			#
			################
			//Validación del nombre
			$this->validateAttrib($comprobanteJson['Receptor']['Nombre'],'240 - El nombre del receptor es requerido');

			//Validación Residencia Fiscal
			if (!empty($comprobanteJson['Receptor']['NumRegIdTrib'])) {
     			if (strlen($comprobanteJson['Receptor']['NumRegIdTrib']) > 40){
					$this->getLogger('Validando numero registro fiscal: '.strlen($comprobanteJson['Receptor']['NumRegIdTrib']));
					throw new Exception('241 - El numero de registro de Tributación debe ser máximo de 40 caracteres');
				}
				$this->validateAttrib($comprobanteJson['Receptor']['ResidenciaFiscal'],'242 - Se debe indicar la residencia fiscal');
				
				$pPais=(new pPais())->check($comprobanteJson['Receptor']['ResidenciaFiscal']);			
				if (count($pPais) == 0)
					throw new Exception('243 - El código del País no es válido');
					
			}

			################
			#
			#   Validación Conceptos
			#
			################
			foreach($comprobanteJson['Conceptos'] as $concepto) {
				//Validación de unidad
				$this->validateAttrib($concepto['Unidad'],'244 - La unidad es requerida');

				//Validaciones para impuestos
				foreach($concepto['Impuestos'] as $impuesto) {
					//Validando TasaOCuota
					if (isset($impuesto[0]['TipoFactor']) && $impuesto[0]['TipoFactor'] != 'Exento') 
						$this->validateAttrib($impuesto[0]['TasaOCuota'],'245 - La Tasa o Cuota es requerida al indicar un Tipo de Factor');
				}
			}
		}
	}

	/**
	 * Obtiene el valor del nodo indicado a partir de un objeto generado con SimpleXML
	 * 
	 * @param  object $xml   Objeto SimpleXML
	 * @param  string $path  Path a cargar por xpath()
	 * @param  string $item  Nombre del nodo/atributo a cargar
	 * @param  string $valor Valor por defecto por si no existe o no tiene valor el noto/atributo llamado
	 * @param  string $tipo  Indica el tipo con el que se retorna el valor del nodo/atrubuto, por defecto 'string'
	 * @return string|float  Valor del nodo/atributo
	 */
	private function getXmlValue($xml, $path, $item, $valor='',$tipo='string') {
		if ($path != '')
			$tmpXml = $xml->xpath($path);
		else
			$tmpXml = $xml;

		if ($tipo == '') {
			switch($tipo) {
				case 'string':
					return (string)$tmpXml[0][$item];
					break;
				case 'float':
					return (float)$tmpXml[0][$item];
					break;
				default:
					return $tmpXml[0][$item];
			}
		}
		else
			return isset($tmpXml[0][$item]) ? (string)$tmpXml[0][$item] : $valor;
	}

	/**
	 * Valida que no este vacio un atributo, de ser así envia una excepcion con el mensaje indicado
	 * 
	 * @param  mixed  $attrib    La variable a validar
	 * @param  string $mssgError El mensaje a enviar a la excepción
	 * @return void
	 */
	private function validateAttrib($attrib,$mssgError) {
		if (empty($attrib)) {
			throw new Exception($mssgError);
		}
	}

	/**
	 * Envia un mensaje y un objeto al logg
	 * 
	 * @param  string $subfijo Una cadena de texto (cabecera) para localizar la linea en el log con facilidad
	 * @param  mixed  $item    La variable a enviar, en caso de ser array u objeto este se imprime con print_r()
	 * @return void
	 */
	private function getLogger($subfijo,$item='') {
		if (!empty($item)) {
			if (is_array($item) || is_object($item))
				$this->logger->info($subfijo.' '.print_r($item,1));
			else
				$this->logger->info($subfijo.' '.$item);
		}else
			$this->logger->info($subfijo);
	}

	private function checkAddress($tipo, $hashFactura, $dato, $data) {
		$q = mysql::getInstance();

		// error_log('___________________');
		// error_log('___________________');
		// error_log('Veficiamos dirección tipo: "'.$tipo.'" del hash "'.$hashFactura.'"');

		// if ($tipo == 'cempresa') {
		// 	$direccionDB = $q->selectGenerico('SELECT cdomicilio.id_cdomicilio, cdomicilio.calle, cdomicilio.no_exterior, cdomicilio.codigo_postal, cdomicilio.no_interior, cdomicilio.localidad, cdomicilio.referencia, cdomicilio.estado, cdomicilio.ciudad, cdomicilio.delegacion_municipio, cdomicilio.colonia FROM cempresa INNER JOIN cdomicilio ON cempresa.id_cdomicilio = cdomicilio.id_cdomicilio WHERE cempresa.rfc = "'.$dato.'" ');
			
		// }else {
		// 	$direccionDB = $q->selectGenerico('SELECT cdomicilio.calle, cdomicilio.no_exterior, cdomicilio.no_interior, cdomicilio.codigo_postal, cdomicilio.referencia, cdomicilio.localidad, cdomicilio.delegacion_municipio, cdomicilio.colonia, cdomicilio.estado, cdomicilio.ciudad FROM csucursal INNER JOIN cdomicilio ON csucursal.id_cdomicilio = cdomicilio.id_cdomicilio WHERE csucursal.clave = "'.$dato.'"');
		// }

		// $direccionDB = $direccionDB[0];

		$noExterior = (isset($data['noExterior']) ? $data['noExterior'] : '');
		$noInterior = (isset($data['noInterior']) ? $data['noInterior'] : '');

		$sql = ''
		. 'INSERT INTO cfactura_direccion (hashFactura,tipo,calle,noExterior,noInterior,colonia,delegacion,ciudad,estado,cp) VALUES ('
		. '"'.$hashFactura.'",'
		. '"'.$tipo.'",'
		. '"'.$data['calle'].'",'
		. '"'.(!empty($data['noExterior']) ? $data['noExterior'] : '').'",'
		. '"'.(!empty($data['noInterior']) ? $data['noInterior'] :  '').'",'
		. '"'.$data['colonia'].'",'
		. '"'.$data['delegacion'].'",'
		. '"'.$data['ciudad'].'",'
		. '"'.$data['estado'].'",'
		. '"'.$data['codigoPostal'].'")';

		error_log('SQL: '.$sql);
		error_log('___________________');
		error_log('___________________');
		$q->insert($sql);
	}
}
?>
