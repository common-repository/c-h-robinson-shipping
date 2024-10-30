<?php
class chr_rest
{
	public $mode = "test";
	public $auth_expiry = 86300;
	public $live_rate_url = "https://api.navisphere.com/v1/quotes";
	public $test_rate_url = "https://sandbox-api.navisphere.com/v1/quotes";
	public $live_auth_url = "https://api.navisphere.com/v1/oauth/token";
	public $test_auth_url = "https://sandbox-api.navisphere.com/v1/oauth/token";
	public $general_settings = [];
	public $ven_data = [];
	public $dest_data = [];
	public $packs = [];
	private $total_pack_count = 0;
	private $total_pack_weight = 0;
	private $weg_unit = "Kilograms";
	private $dim_unit = "Centimeters";
	public function gen_access_token($grant_type='', $client_key='', $client_secret='')
	{
		$request_url = ($this->mode == "test") ? $this->test_auth_url : $this->live_auth_url;
		$AuthAry = [
					"client_id" => $client_key, 
					"client_secret" => $client_secret, 
					"audience" => "https://inavisphere.chrobinson.com", 
					"grant_type" => $grant_type
				];
		$result = wp_remote_post(
			$request_url,
			array(
				'method' => 'POST',
				'timeout' => 70,
				'sslverify' => 0,
				'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
				'body' => json_encode($AuthAry)
			)
		);
		if (isset($result['body']) && !empty($result['body'])) {
			$auth_data = json_decode($result['body']);
			if (isset($auth_data->expires_in) && isset($auth_data->access_token)) {
				$this->auth_expiry = ($auth_data->expires_in - 100);
				return $auth_data->access_token;
			}
		}
		return;
	}
	public function make_rate_req_rest($general_settings=[], $ven_data=[], $dest_data=[], $packs=[], $order_total = 0){
		//load all data to class
		$this->general_settings = $general_settings;
		$this->ven_data = $ven_data;
		$this->dest_data = $dest_data;
		$this->packs = $packs;
		// $this->order_total = $order_total;
		//make req params

		$pic_days_aftr = (isset($this->general_settings['shipi_chr_pickup_date']) && !empty($this->general_settings['shipi_chr_pickup_date'])) ? $this->general_settings['shipi_chr_pickup_date'] : "1";
		$pic_open_time = (isset($this->general_settings['shipi_chr_pickup_open_time']) && !empty($this->general_settings['shipi_chr_pickup_open_time'])) ? $this->general_settings['shipi_chr_pickup_open_time'] : "12:30";
		
		date_default_timezone_set('UTC');
		$current_time_utc = gmdate('Y-m-d H:i:s');
		$current_time_utc = new DateTime($current_time_utc, new DateTimeZone('UTC'));
		$date_array = explode(':', $pic_open_time);
		$hour = isset($date_array[0]) ? $date_array[0] : '10';
		$minute = isset($date_array[1]) ? $date_array[1] : '00';
		$current_time_utc->setTime($hour, $minute);
		$date = $current_time_utc->format('Y-m-d H:i:s');
		$date = strtotime("+".$pic_days_aftr." weekdays", strtotime($date));
		$openDateTime = date('Y-m-d\TH:i:s\Z', $date);

		$req = [];
		$req['items'] = $this->get_package_info();
		$req['origin'] = $this->get_shipper_info();
		$req['destination'] = $this->get_receiver_info();
		$req['shipDate'] = $openDateTime;
		$req['customerCode'] = isset($this->ven_data['shipi_chr_cus_code']) ? $this->ven_data['shipi_chr_cus_code'] : "";
		$req['transportModes'] = $this->get_mode_info();
		return $req;
	}
	public function get_rate_res_rest($req_data = [], $auth_token = "")
	{
		$request_url = ($this->mode == "test") ? $this->test_rate_url : $this->live_rate_url;
		$result = wp_remote_post(
			$request_url,
			array(
				'method' => 'POST',
				'timeout' => 70,
				'sslverify' => 0,
				'body' => json_encode($req_data),
				'headers' => array("content-type" => "application/json", "Authorization" => "Bearer " . $auth_token)
			)
		);
		if (isset($result['body']) && !empty($result['body'])) {
			$res_body = json_decode($result['body']);
			return $res_body;
		}
		return;
	}
	private function get_package_info()
	{
		$items = [];
		foreach ($this->packs as $p_key => $pack) {
			$this->total_pack_count += 1;
			$this->total_pack_weight += (isset($pack['Weight']['Value']) && !empty($pack['Weight']['Value'])) ? round($pack['Weight']['Value'], 2) : "0.5";
			$this->weg_unit = (isset($pack['Weight']['Units']) && $pack['Weight']['Units'] == "LBS") ? "Pounds" : "Kilograms";
			$this->dim_unit = (isset($pack['Dimensions']['Units']) && $pack['Dimensions']['Units'] == "IN") ? "Inches" : "Centimeters";
			$curr_item['freightClass'] = isset($this->general_settings['shipi_chr_fright_class']) ? $this->general_settings['shipi_chr_fright_class'] : "";
			$curr_item['actualWeight'] = isset($pack['Weight']['Value']) ? $pack['Weight']['Value'] : "";
			$curr_item['weightUnit'] = $this->weg_unit;
			if (isset($pack['Dimensions'])) {
				$curr_item['length'] = isset($pack['Dimensions']['Length']) ? $pack['Dimensions']['Length'] : "0.5";
				$curr_item['width'] = isset($pack['Dimensions']['Width']) ? $pack['Dimensions']['Width'] : "0.5";
				$curr_item['height'] = isset($pack['Dimensions']['Height']) ? $pack['Dimensions']['Height'] : "0.5";
				$curr_item['linearUnit'] = $this->dim_unit;
			}
			$curr_item['declaredValue'] = isset($pack['InsuredValue']['Amount']) ? $pack['InsuredValue']['Amount'] : "0";
			$curr_item['packagingCode'] = "PKG";
			$curr_item['productCode'] = "PKG ".$this->total_pack_count;
			$curr_item['productName'] = $this->get_prod_name_info($pack['packed_products']);
			$curr_item['nmfc'] = isset($this->general_settings['shipi_chr_nmfc']) ? $this->general_settings['shipi_chr_nmfc'] : "";
			$curr_item['pieces'] = 1;

			$items[] = $curr_item;
		}
		return $items;
	}
	private function get_prod_name_info($packed_products=[])
	{
		$names = "";
		if(!empty($packed_products) && is_array($packed_products)){
			foreach ($packed_products as $key => $prod) {
			    if(is_array($prod) && isset($prod['product_name'])){
			        $names .= $prod['product_name'];
			    } elseif(is_array($prod) && isset($prod['data'])) {
			        $curr_prod_data = $prod['data']->get_data();
			        $names .= isset($curr_prod_data['name']) ? $curr_prod_data['name'] : "";
			    } else {
			        $names .= $prod->get_name();
			    }
				//$names .= (is_array($prod) && isset($prod['product_name'])) ? $prod['product_name'] : $prod->get_name();
			}
		} elseif (!empty($packed_products)) {
			$names .= (is_array($packed_products) && isset($packed_products['product_name'])) ? $packed_products['product_name'] : $packed_products->get_name();
		}
		return substr($names, 0, 49);
	}
	private function get_shipper_info()
	{
		$shipper = [];
		$shipper['address1'] = isset($this->ven_data['shipi_chr_address1']) ? $this->ven_data['shipi_chr_address1'] : "";
		if (isset($this->ven_data['shipi_chr_address2']) && !empty($this->ven_data['shipi_chr_address2'])) {
			$shipper['address2'] = $this->ven_data['shipi_chr_address2'];
		}
		$shipper['city'] = isset($this->ven_data['shipi_chr_city']) ? $this->ven_data['shipi_chr_city'] : "";
		$shipper['stateProvinceCode'] = isset($this->ven_data['shipi_chr_state']) ? $this->ven_data['shipi_chr_state'] : "";
		$shipper['countryCode'] = isset($this->ven_data['shipi_chr_country']) ? $this->ven_data['shipi_chr_country'] : "";
		$shipper['postalCode'] = isset($this->ven_data['shipi_chr_zip']) ? $this->ven_data['shipi_chr_zip'] : "";
		
		$shipper['specialRequirement']['liftGate'] = (isset($this->general_settings['shipi_chr_shipper_lifegate']) && $this->general_settings['shipi_chr_shipper_lifegate'] == "yes") ? "true": "false" ;			
		$shipper['specialRequirement']['insidePickup'] = (isset($this->general_settings['shipi_chr_shipper_insidepickup']) && $this->general_settings['shipi_chr_shipper_insidepickup'] == "yes") ? "true":"false";			
		$shipper['specialRequirement']['residentialNonCommercial'] = (isset($this->general_settings['shipi_chr_residential']) && $this->general_settings['shipi_chr_residential'] == "yes") ? "true": "false";		
		$shipper['specialRequirement']['limitedAccess'] = (isset($this->general_settings['shipi_chr_shipper_limitedaccess']) && $this->general_settings['shipi_chr_shipper_limitedaccess'] == "yes") ? "true" : "false"; 		
		$shipper['specialRequirement']['tradeShoworConvention'] = (isset($this->general_settings['shipi_chr_shipper_tradeshowor']) && $this->general_settings['shipi_chr_shipper_tradeshowor'] == "yes") ? "true": "false";
		$shipper['specialRequirement']['constructionSite'] = (isset($this->general_settings['shipi_chr_shipper_consite']) && $this->general_settings['shipi_chr_shipper_consite'] == "yes") ? "true" : "false"; 		
		$shipper['specialRequirement']['dropOffAtCarrierTerminal'] = (isset($this->general_settings['shipi_chr_shipper_dropoff']) && $this->general_settings['shipi_chr_shipper_dropoff'] == "yes") ? "true" : "false";			
		
		return $shipper;
	}
	private function get_receiver_info()
	{
		$receiver = [];
		$receiver['address1'] = isset($this->dest_data['address_1']) ? $this->dest_data['address_1'] : "";
		if (isset($this->dest_data['address_2']) && !empty($this->dest_data['address_2'])) {
			$receiver['address2'] = $this->dest_data['address_2'];
		}
		$receiver['city'] = isset($this->dest_data['city']) ? $this->dest_data['city'] : "";
		$receiver['stateProvinceCode'] = (isset($this->dest_data['state']) && isset($this->dest_data['country'])) ? str_replace($this->dest_data['country']."-", "", $this->dest_data['state']) : "";
		$receiver['countryCode'] = isset($this->dest_data['country']) ? $this->dest_data['country'] : "";
		$receiver['postalCode'] = isset($this->dest_data['postcode']) ? $this->dest_data['postcode'] : "";
		
		$receiver['specialRequirement']['liftGate'] = (isset($this->general_settings['shipi_chr_reciver_lifegate']) && $this->general_settings['shipi_chr_reciver_lifegate'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['insideDelivery'] = (isset($this->general_settings['shipi_chr_reciver_insidedelivery']) && $this->general_settings['shipi_chr_reciver_insidedelivery'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['residentialNonCommercial'] = (isset($this->general_settings['shipi_chr_reciver_residential']) && $this->general_settings['shipi_chr_reciver_residential'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['limitedAccess'] = (isset($this->general_settings['shipi_chr_reciver_limitedaccess']) && $this->general_settings['shipi_chr_reciver_limitedaccess'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['tradeShoworConvention'] = (isset($this->general_settings['shipi_chr_reciver_tradeshowor']) && $this->general_settings['shipi_chr_reciver_tradeshowor'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['constructionSite'] = (isset($this->general_settings['shipi_chr_reciver_consite']) && $this->general_settings['shipi_chr_reciver_consite'] == "yes") ? "true": "false";
		$receiver['specialRequirement']['pickupAtCarrierTerminal'] = (isset($this->general_settings['shipi_chr_reciver_pickup']) && $this->general_settings['shipi_chr_reciver_pickup'] == "yes") ? "true":"false";
		
		return $receiver;
	}
	private function get_mode_info()
	{
		$modes = [];
		if (isset($this->general_settings['shipi_chr_carrier']) && !empty($this->general_settings['shipi_chr_carrier'])) {
			$mode_k = 0;
			foreach ($this->general_settings['shipi_chr_carrier'] as $c_key => $carrier) {
				if ($carrier == "yes") {
					$modes[$mode_k]['mode'] = $c_key;
					$mode_k++;
				}
			}
		}
		return $modes;
	}
}