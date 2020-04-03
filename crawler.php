<?php 

use AntiCaptcha\NoCaptchaProxyless;

class Crawler{
	protected $url_capcha = 'http://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx?tipoConsulta=completa&tipoConteudo=XbSeqxE8pl8=';
	
	protected $url_xml = 'http://www.nfe.fazenda.gov.br/portal/consultaCompleta.aspx?tipoConteudo=XbSeqxE8pl8=';

	protected $text_html = '';

	protected $html;

	protected $chave;

	protected $patch_captcha;

	protected $cnpj;

	protected $password;

	protected $post = array(
		'__EVENTTARGET' => '',
		'__EVENTARGUMENT' => '',
		'__VIEWSTATE' => '',
		'__VIEWSTATEGENERATOR' => '',
		'__EVENTVALIDATION' => '',
		'ctl00$txtPalavraChave' => '',
		'ctl00$ContentPlaceHolder1$txtChaveAcessoCompleta' => '',
		'ctl00$ContentPlaceHolder1$btnConsultar' => 'Continuar',
		'ctl00$ContentPlaceHolder1$token' => '',
		'ctl00$ContentPlaceHolder1$captchaSom' => '',
		'hiddenInputToUpdateATBuffer_CommonToolkitScripts' => 1,
		'g-recaptcha-response' => ''
	);

	protected $post_xml = array(
		'__EVENTTARGET' => '',
		'__EVENTARGUMENT' => '',
		'__VIEWSTATE' => '',
		'__VIEWSTATEGENERATOR' => '',
		'__EVENTVALIDATION' => '',
		'ctl00$txtPalavraChave' => '',
		'ctl00$ContentPlaceHolder1$btnDownload' => '',
		'ctl00$ContentPlaceHolder1$abaSelecionada' => '',
		'hiddenInputToUpdateATBuffer_CommonToolkitScripts' => '',
	);

	protected $tokenAntiCaptcha = '';

	function __construct($chave, $cnpj, $password){

		set_time_limit(0);

		error_reporting(1);

		ini_set("display_errors","On");

		$this->chave = preg_replace('/\D/', '', $chave);

		$this->clearSessionCurl();

		$this->cnpj = $cnpj;

		$this->password = $password;

		if (session_status() == PHP_SESSION_NONE)
            session_start();
	}

	public function getXML(){

		if (strlen($this->chave) != 44){
			
	        $this->logChaveError($this->chave , 'Chave CTe invalida ela deve ter 44 digitos');

			return false;
		}

		$html = $this->execCurl($this->url_capcha, 'GET', null);

		$this->text_html = $html;

		$this->html = new DOMDocument();

		$this->html->loadHTML($this->text_html);

		$key = $this->getCaptcha($this->html);

		if (!$key){
			
	        $this->logChaveError($this->chave , 'Não foi possivel achar o captch');

			return false;
		}

		$this->fillPost();

		$text_capcth = $this->resolveCaptcha($key);

		$this->post['ctl00$ContentPlaceHolder1$txtChaveAcessoCompleta'] = $this->chave;

		if ($text_capcth){
			
			$this->post['g-recaptcha-response'] = $text_capcth;

			$html = $this->execCurl($this->url_capcha, 'POST', $this->post);

			$this->text_html = $html;

			$this->html = new DOMDocument();

			$this->html->loadHTML($this->text_html);

			preg_match('~Dados da NF-e~', $html, $tagTeste);

			if (isset($tagTeste[0])) {
            	
            	$tagDownload = $tagTeste[0];

            	$viewstate = $this->html->getElementById('__VIEWSTATE')->getAttribute('value');
        		
        		$stategen = $this->html->getElementById('__VIEWSTATEGENERATOR')->getAttribute('value');
        
        		$eventValidation = $this->html->getElementById('__EVENTVALIDATION')->getAttribute('value');

        		if (!is_file('cert/' . $this->cnpj . '_priKEY.pem') || !is_file('cert/' . $this->cnpj . '_pubKEY.pem') || !is_file('cert/' . $this->cnpj . '_certKEY.pem')){

        			throw new Exception("O certificado não existe na pasta cert", 1);
        			
        		}

	       		$cert = array(
	       			'prikey' => 'cert/' . $this->cnpj . '_priKEY.pem',
	       			'pubkey' => 'cert/' . $this->cnpj . '_pubKEY.pem',
	       			'certkey' => 'cert/' . $this->cnpj . '_certKEY.pem',
	       			'password' => $this->password
	       		);

	            $this->post_xml['__EVENTTARGET'] = "";
	            
	            $this->post_xml['__EVENTARGUMENT'] = "";
	            
	            $this->post_xml['__VIEWSTATE'] = $viewstate;
	            
	            $this->post_xml['__VIEWSTATEGENERATOR'] = $stategen;
	            
	            $this->post_xml['__EVENTVALIDATION'] = $eventValidation;
	            
	            $this->post_xml['ctl00$txtPalavraChave'] = '';
	            
	            $this->post_xml['ctl00$ContentPlaceHolder1$btnDownload'] = 'Download do documento*';
	            
	            $this->post_xml['ctl00$ContentPlaceHolder1$abaSelecionada'] = '';
	            
	            $this->post_xml['hiddenInputToUpdateATBuffer_CommonToolkitScripts'] = 1;

				$xml = $this->execCurl($this->url_xml, 'POST', $this->post_xml, $cert);

				if(!$this->saveXML($xml)){
	            	
	            	$this->logChaveError($this->chave , 'Sessão expirada ou captcha inválido, gere um novo captcha e tente novamente, ao tentar baixar xml');

	            	return false;
				} else {

	            	return true;
				}

	        } else {
	            
	            $this->logChaveError($this->chave , 'Sessão expirada ou captcha inválido, gere um novo captcha e tente novamente.');

	            return false;
	        }

		} else {
			$this->logChaveError($this->chave , 'Erro ao resolver captcha');
		}
		

	}

	private function getCaptcha($html){
		
		try{
			$xpath = new \DOMXpath($html);
  			
  			$element_captcha = $xpath->query('//div[@class="g-recaptcha"]');

			if ($element_captcha){

				foreach($element_captcha as $el) {
					$key = $el->getAttribute('data-sitekey');
					break;
				}

				return $key;
			} else {
				return false;
			}
		} catch(\Exception $e){
			return false;
		}
		
	}

	private function execCurl($url, $method, $data, $certificado = null){
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);

		if ($method == 'POST')
			curl_setopt($ch, CURLOPT_POST, true);

		if ($data)
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36');

		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($ch, CURLOPT_COOKIEJAR, "cookie.txt");
		
		curl_setopt($ch, CURLOPT_COOKIEFILE, "cookie.txt"); //saved cookies

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        curl_setopt($ch, CURLOPT_REFERER, 'http://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx');

        if ($certificado){
        	curl_setopt($ch, CURLOPT_SSLKEY, $certificado['prikey']);

            curl_setopt($ch, CURLOPT_CAINFO, $certificado['certkey']);

            curl_setopt($ch, CURLOPT_SSLCERT, $certificado['pubkey']);

            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certificado['password']);
        }

		return curl_exec($ch);
	}

	private function base64_to_jpeg($base64_string, $output_file) {
	    

	    $ifp = fopen( $output_file, 'wb' ); 

	    // split the string on commas
	    // $data[ 0 ] == "data:image/png;base64"
	    // $data[ 1 ] == <actual base64 string>
	    $data = explode( ',', $base64_string );

	    fwrite( $ifp, base64_decode( $data[ 1 ] ) );

	    fclose( $ifp ); 

	    return $output_file; 
	}

	private function resolveCaptcha($key){

		try{

			$api = new NoCaptchaProxyless();

			$api->setVerboseMode(false);

			$api->setKey($this->tokenAntiCaptcha);

			$api->setWebsiteURL($this->url_capcha);

			$api->setWebsiteKey($key);
			
			// //browser header parameters
			// $api->setUserAgent("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.167 Safari/537.36");

			if (!$api->createTask()) {
			    
			    return false;
			}

			$taskId = $api->getTaskId();

			if (!$api->waitForResult()) {
			   
			   return false;
			
			} else {

			    return $api->getTaskSolution();
			}
		} catch(\Exception $e){
			var_dump($e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getFile());

			return false;
		}
	}

	private function logChaveError($chave, $message){
		return file_put_contents('log/log.txt', $chave . ' : ' . $message . PHP_EOL, FILE_APPEND);
	}

	private function fillPost (){
		
		$xpath = new DomXpath($this->html);

		foreach ($this->post as $key => $post_value) {

			foreach ($xpath->query('//input[@name="' . $key . '"]') as $rowNode) {
				
				if($rowNode->getAttribute('value'))
			    	$this->post[$key] = $rowNode->getAttribute('value');
			}
		}
	}
	
	private function saveXML($xml){
		$file = $this->chave . '-nfe.xml';

		$folder = 'xml/';

		$xml_parse = simplexml_load_string($xml);

		if ($xml){
			return file_put_contents($folder . $file, $xml);
		}

		return false;
	}

	private function clearSessionCurl(){
		unlink('cookie.txt');
	}

	public function setTokenAntiCaptcha($token){
		$this->tokenAntiCaptcha = $token;
	}
}

?>