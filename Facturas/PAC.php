<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php';
require_once 'BD/cEmpresa.php';
require_once 'BD/cFactura.php';
require_once 'BD/cSerie.php';

class PAC
{
	//La URL_API cambia en el metodo timbrar() según la versión del objeto (3.2 o 3.3)
  const URL_API = "https://cfdi33.facemasnegocio.com/";//3.3
//const URL_API = "http://qa.pacmasnegocio.com:2379/";
	private $loggerConfidential;

	public function __construct()
	{
		Logger::configure(__DIR__.DIRECTORY_SEPARATOR."log4php.properties");
		
		$this->logger 				= Logger::getLogger(basename(__FILE__));
		$this->loggerConfidential 	= Logger::getLogger("CONFIDENTIAL");
	}

	public static function timbrar($objeto, $details)
	{
		error_log('Objeto_org: '.print_r($objeto,1));
		if (!isset($objeto->Version)) {
			error_log('Objeto32: '.json_encode($objeto));
			$data = [
				"no_referencia" 	=> $details->numeroReferenciaFacturacion,
				"peticion" 			=> $details->peticion,
				"id_csucursal" 		=> $details->idSucursal,
				"id_cempresa" 		=> $details->empresa,
				"id_corigen" 		=> $details->origen,
				"fecha_emision" 	=> $objeto->comprobante->fecha,
				"monto_total" 		=> $objeto->comprobante->total,
				"subtotal" 			=> $objeto->comprobante->subTotal,
				"iva" 				=> $objeto->comprobante->impuestos->totalImpuestosTrasladados,
				"rfc_receptor" 		=> $objeto->comprobante->receptor->rfc,
				"forma_pago" 		=> $objeto->comprobante->formaDePago,
				"serie" 			=> $objeto->comprobante->serie,
				"folio" 			=> $objeto->comprobante->folio,
				"nombre_receptor" 	=> $objeto->comprobante->receptor->nombre,
				"estatus" 			=> 1,
				"tipoDocumento" 	=> (strtoupper($objeto->comprobante->tipoDeComprobante) == strtoupper("ingreso")) ? 'I' : 'E',
				"metodoDePago" 		=> $objeto->comprobante->metodoDePago,
				"moneda" 			=> $objeto->comprobante->moneda,
				"tipoCambio" 		=> $objeto->comprobante->tipoCambio,
				"numeroTicket" 		=> $details->noTicket,
				"codigoSucursal" 	=> $details->codSuc
			];
		}else {
			error_log('Objeto33: '.json_encode($objeto));
			$data = [
				"no_referencia" 	=> $details->numeroReferenciaFacturacion,
				"peticion" 			=> $details->peticion,
				"id_csucursal" 		=> $details->idSucursal,
				"id_cempresa" 		=> $details->empresa,
				"id_corigen" 		=> $details->origen,
				"fecha_emision" 	=> $objeto->ComprobanteJson->Fecha,
				"monto_total" 		=> $objeto->ComprobanteJson->Total,
				"subtotal" 			=> $objeto->ComprobanteJson->SubTotal,
				"iva" 				=> (isset($objeto->ComprobanteJson->Impuestos->TotalImpuestosTrasladados) ? $objeto->ComprobanteJson->Impuestos->TotalImpuestosTrasladados : ''),
				"rfc_receptor" 		=> $objeto->ComprobanteJson->Receptor->Rfc,
				"forma_pago" 		=> (isset($objeto->ComprobanteJson->FormaPago) ? $objeto->ComprobanteJson->FormaPago : ''),
				"serie" 			=> $objeto->ComprobanteJson->Serie,
				"folio" 			=> $objeto->ComprobanteJson->Folio,
				"nombre_receptor" 	=> $objeto->ComprobanteJson->Receptor->Nombre,
				"estatus" 			=> 1,
				"tipoDocumento" 	=> $objeto->ComprobanteJson->TipoDeComprobante,
				"metodoDePago" 		=> (isset($objeto->ComprobanteJson->MetodoPago) ? $objeto->ComprobanteJson->MetodoPago : ''),
				"moneda" 			=> $objeto->ComprobanteJson->Moneda,
				"tipoCambio" 		=> (isset($objeto->ComprobanteJson->TipoCambio) ? $objeto->ComprobanteJson->TipoCambio : ''),
				"numeroTicket" 		=> (isset($details->noTicket) ? $details->noTicket : ''),
				"codigoSucursal" 	=> $details->codSuc
			];
		}
		
		/*if($objeto->emisor->rfc == "GMA8108184L9"){
			$objeto->emisor->rfc = 'ZUN100623663';
			$objeto->noCertificado = '20001000000200000276';
		}*/
		
		 $peticion = [
			"tipo" 			=> "JSON", 
			"comprobante" 	=> $objeto,
			"noControl" 	=> uniqid()
		];

		//Verificamos la versión del objeto y dependiendo enviamos la
		//petición a una u otra URL
		if (!isset($objeto->Version))
			$urlAPI = 'http://200.53.162.96:2376/facturas/emision';
		else
			$urlAPI = self::URL_API."cfdi/emision";

		try{
			$nombre = isset($objeto->ComprobanteJson->Emisor->Nombre) ? $objeto->ComprobanteJson->Emisor->Nombre : $objeto->comprobante->emisor->nombre;
			$nombre = preg_replace('/([,.])/', '', $nombre);
			$nombre = str_replace(['í','Í','S A'], ['i','I','SA'], $nombre);
			if (isset($objeto->ComprobanteJson->Emisor->Nombre ))
				$objeto->ComprobanteJson->Emisor->Nombre = $nombre;
			else
				$objeto->comprobante->emisor->nombre = $nombre;
			
			error_log('Object PAC: '.print_r($objeto,1));
			error_log('JSON PAC: '.json_encode($objeto));

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $urlAPI);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST"); 
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($objeto));
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Token: '.$details->token
			]);

			error_log('Token: '.print_r($details->token,1));
			//error_log('Request: '.json_encode($peticion));

			$curl_response = curl_exec($curl);
			$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);
			$response = json_decode($curl_response);
			error_log('Response: '.print_r($response,1));
			if(empty($response))
				throw new Exception("1000 - Servicio de timbrado no disponible por el momento.");

			if($httpcode != 200){
				error_log('Response2: '.print_r($response,1));
				$errorCode = (!isset($response->clave)) ? "1001" : $response->Error->CodigoError;
				//$error = (!is_array($response)) ? $errorCode." - ".$response->Error->Error  : $response[0]->clave." - ".$response[0]->mensaje;

				$resError = $response->Error;
				$error = '('.$resError->Observaciones.'|'.$resError->CodigoError.') '.$resError->Error;

				throw new Exception($error);
			}

			(new PAC())->loggerConfidential->debug("PAC referencia: ".$data['no_referencia']);

			(new PAC())->loggerConfidential->debug($response);

			$response->folio = (isset($objeto->Version) ? $objeto->ComprobanteJson->Folio : $objeto->comprobante->folio);
			if (!isset($objeto->Version)) {
				$result = json_decode((new cFactura())->insertarFactura($data+[
					"UUID" 				=> $response->uuid,
					"CFDI" 				=> $response->facturaTimbradaBase64,
					"fecha_timbrado" 	=> $response->fechaTimbrado
				]));
			}else if ($objeto->ComprobanteJson->TipoDeComprobante != 'P') {
				$result = json_decode((new cFactura())->insertarFactura($data+[
					"UUID" 				=> $response->Uuid,
					"CFDI" 				=> $response->DocumentoTimbradoBase64,
					"fecha_timbrado" 	=> $response->FechaTimbrado
				]));
			}
			
			error_log('Response: '.print_r($response,1));
			return $response;
		}catch(Exception $e){
			throw new Exception($e->getMessage());
		}
	}

		public static function cancelar($parametros, $details)
	{
		$respuesta = json_decode($parametros);
		$response;
		if($details->rfc == "GMA8108184L9"){ 
			$details->rfc = "ZUN100623663";
		}	
		$data = array(
					    "UUID" 			=> $respuesta->respuesta->uuid,
					    "RfcEmisor" 	=> $details['RfcEmisor'],
					   "RfcReceptor" 	=> $details['RfcReceptor'], 
					   "Total" 			=> $details['Total'],
					   "Id" 	        => $details['Id'],
	        		"NoCertificado" 	=> $details['certificado'],                   
		 			"FechaTimbrado" 	=> $details['fechaTimbrado']);
		
		 $peticion = array("Tipo" => "JSON", 
					"datos" => $data);
		try{
			$peticion33 = [
				'Tipo' 	=> 'json',
				'Datos' => [
					'UUID' 				=> $peticion['datos']['UUID'],
					'RfcEmisor' 		=> $peticion['datos']['RfcEmisor'],
					'RfcReceptor' 		=> $peticion['datos']['RfcReceptor'],
                    'Total' 		    => $peticion['datos']['total'],
                 // "Uri"              => "http://200.53.162.205/marti/tiendas/face_dev/com/proceso/_facturas_cancel/api-cancel.php",
                    // "Uri"              => "http://172.20.53.113/marti/face/marti/com/proceso/_facturas_cancel/api-cancel.php",
                    "Uri"               => "https://facemarti.masnegocio.com/marti/face/marti/com/proceso/_facturas_cancel/api-cancel.php",
					'Id' 	            => $peticion['datos']['Id'],
					'NoCertificado' 	=> $peticion['datos']['NoCertificado'],
					'FechaTimbrado' 	=> $peticion['datos']['FechaTimbrado'],
					'LiberarNoControl'	=> false
				],
				'CancelacionXMLBase64'  => null,
				'Version'				=> '3.3'
			];
           
			error_log ("Cancelar PAC 33 : ".print_r($peticion33,true));

			$curl = curl_init();
			//curl_setopt($curl, CURLOPT_URL, 'http://qa.pacmasnegocio.com:2379/cfdi/cancelacion');
			curl_setopt($curl, CURLOPT_URL, 'https://cfdi33.facemasnegocio.com/cfdi/cancelacion');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST"); 
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($peticion33));
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
						    							'Token: '.$details['token']));
			$curl_response = curl_exec($curl);
			$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);
			$response = json_decode($curl_response);
			error_log('PAC Response: '.json_encode($response));
			$response->estatus = $httpcode;

			if(empty($response))
				throw new Exception("1000 - Servicio de cancelación no disponible por el momento.");
			
			if(is_array($response->Error) && count($response->Error) > 0) {
				if ($response->Error->CodigoError == 'CodigoError') {
					error_log('Al parecer es version 3.2, enviando petición a PAC4');

					$curl = curl_init();
				    curl_setopt($curl, CURLOPT_URL, 'https://api.pacmasnegocio.com/facturas/cancelacion');
					//curl_setopt($curl, CURLOPT_URL, 'http://qa.pacmasnegocio.com:2379/cfdi/cancelacion');
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST"); 
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($peticion));
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
								    							'Token: '.$details->token));
					$curl_response = curl_exec($curl);
					$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
					curl_close($curl);
					$response = json_decode($curl_response);
					error_log('PAC Response: '.json_encode($response));
					$response->estatus = $httpcode;

					if(empty($response))
						throw new Exception("1000 - Servicio de cancelación no disponible por el momento.");

					if($response->mensaje != "Solicitud de cancelación almacenada exitosamente")
						throw new Exception("159 - ".$response->mensaje, 7);
				}else {
					throw new Exception("159 - ".$response->Error->Error, 7);
				}
			}
			
			return $response;

		}catch(Exception $e){
			throw new Exception($e->getMessage());
		}
	}


	public static function altaEmpresa($usuario, $token)
	{
		$usuario = preg_replace('/\.|,|-/', '', strtoupper($usuario));		
		$elements = explode(" ", $usuario);
		$usuarioAux = "";
		foreach ($elements as $key => $value) {
			if(strlen($value) > 2)
				$usuarioAux.= substr($value, 0, 2);
		}
		$usuario = $usuarioAux;
		$usuario = preg_replace('/\s/', '', strtoupper($usuario));		 

		if(strlen($usuario) > 12)
			$usuario = substr($usuario, 0, 12);
		else{
			$faltantes = 12-strlen($usuario);
			$usuario.= substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $faltantes);
		}

		$numeros = ['4', '8', '3', '1', '0', '6', '7', '2', '5', ''];
		$letras = ['/A/', '/B/', '/E/', '/1/', '/O/', '/G/', '/T/', '/Z/', '/S/', '/\s/'];
		$pass = preg_replace($letras, $numeros, $usuario);

		$requestAltaUsuario = new RequestAltaUsuario($usuario."DM_API", $pass);
		error_log('Request: '.print_r(json_encode($requestAltaUsuario),1));
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, self::URL_API.'api/usuarios');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($requestAltaUsuario));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
					    							'Token: '.$token));
		error_log('Token: '.$token);


		$curl_response = curl_exec($curl);
		$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$response = json_decode($curl_response);
		
		if(empty($response))
			throw new Exception("1000A - Servicio de Alta de usuarios no disponible por el momento.");			
		if($httpcode >= 400)
			throw new Exception("159 - ".$response->mensaje);
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, self::URL_API."seguridad/login");
		//curl_setopt($curl, CURLOPT_URL, 'http://200.53.162.96:2376/seguridad/login');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST"); 
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([]));
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, $usuario."DM_API:".$pass);	
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);		
		$response = curl_exec($curl);

		
		(new PAC())->logger->info("PAC - Alta Empresa: ".$response);
		//error_log(print_R($response, true));

		$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$response = json_decode($response);

		(new PAC())->logger->info("PAC - Alta Empresa: ".json_encode($response));
		(new PAC())->logger->info("PAC - Alta Empresa: ".$httpcode);
		//error_log(print_R($response, true));
		//error_log(print_R($httpcode, true));

		if(empty($response))
			throw new Exception("1000T - Servicio de Alta de usuarios no disponible por el momento.");			
		if($httpcode >= 400)
			throw new Exception("159 - ".$response->mensaje);
	
		return $response;
	}
}

class RequestAltaUsuario
{
	public $id;
	public $password;
	public $empresa;

	public function __construct($id, $password, $empresa = 3)
	{
		$this->id = $id;
		$this->password = $password;
		$this->empresa = $empresa;
	}
}

?>
