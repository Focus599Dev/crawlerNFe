<?php 
include ('anticaptcha/anticaptcha.php');
include ('anticaptcha/nocaptchaproxyless.php');
include ('spreadsheet-reader/php-excel-reader/excel_reader2.php');
include ('spreadsheet-reader/SpreadsheetReader.php');
include ('crawler.php');	

$Reader = new SpreadsheetReader('chaves.xls');

foreach ($Reader as $row){
	try{
		
		if (trim($row[0])){

			if (!is_file('xml/' . preg_replace('/\D/', '', trim($row[0])) . '-nfe.xml' )){
				
				print($row[0] . '<br/>');

				$cw = new Crawler(trim($row[0]), 'cnpj', 'senha');

				$cw->setTokenAntiCaptcha('');
				
				$cw->getXML();

			} else{
				// print($row[0] . '<br/>');
				
			} 
				
		}

	} catch(Exception $e){
		var_dump($e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getFile());
	}

}

var_dump('final');

?>