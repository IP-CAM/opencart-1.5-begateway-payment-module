<?php
class ControllerPaymentBegateway extends Controller {
  const API_VERSION = 2.1;

  public function index() {
    $this->language->load('payment/begateway');
    $this->load->model('checkout/order');

    $this->data['button_confirm'] = $this->language->get('button_confirm');
    $this->data['token_error'] = $this->language->get('token_error');

    $token_request = $this->generateToken();

    if ($token_request == false) {
      $token_request = array(
        'token' => false,
        'action' => '',
      );
    }

    $this->data['token'] = $token_request['token'];
    $this->data['action'] = $token_request['action'];

    if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/begateway.tpl')) {
      $this->template = $this->config->get('config_template') . '/template/payment/begateway.tpl';
    } else {
      $this->template = 'default/template/payment/begateway.tpl';
    }
    $this->response->setOutput($this->render());
  }

  public function generateToken(){

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    $orderAmount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
    $orderAmount = (float)$orderAmount * pow(10,(int)$this->currency->getDecimalPlace($order_info['currency_code']));
    $orderAmount = intval(strval($orderAmount));

    # simplecheckout hack to fix issue with not valid emails
    # when customer didn't enter it
    $email = $order_info['email'];
    $email = $email == 'empty@localhost' ? null : $email;

    $customer_array =  array (
      'address' => $order_info['payment_address_1'],
      'first_name' => $order_info['payment_firstname'],
      'last_name' => $order_info['payment_lastname'],
      'country' => $order_info['payment_iso_code_2'],
      'city'=> $order_info['payment_city'],
      'phone' =>$order_info['telephone'],
      'email'=> $email,
      'zip' => $order_info['payment_postcode']
    );

    foreach ($customer_array as $k => $v) {
      if (strlen($v) == 0)
        unset($customer_array[$k]);
    }

    if (in_array($order_info['payment_iso_code_2'], array('US','CA'))) {
      $customer_array['state'] = $order_info['payment_zone_code'];
    }

    $order_array = array ( 'currency'=> $order_info['currency_code'],
      'amount' => $orderAmount,
      'description' => $this->language->get('text_order') . ' ' .$order_info['order_id'],
      'tracking_id' => $order_info['order_id']);

    $callback_url = $this->url->link('payment/begateway/callback1', '', 'SSL');
    $callback_url = str_replace('carts.local', 'webhook.begateway.com:8443', $callback_url);

    $setting_array = array ( 'success_url'=>$this->url->link('payment/begateway/callback', '', 'SSL'),
      'decline_url'=> $this->url->link('checkout/checkout', '', 'SSL'),
      'cancel_url'=> $this->url->link('checkout/checkout', '', 'SSL'),
      'fail_url'=>$this->url->link('checkout/checkout', '', 'SSL'),
      'customer_fields' => array('hidden' => array('address')),
      'language' => $this->_language($this->session->data['language']),
      'notification_url'=> $callback_url);

    $transaction_type='payment';

    $checkout_array = array(
      'version' => self::API_VERSION,
      'transaction_type' => $transaction_type,
      'settings' =>$setting_array,
      'order' => $order_array,
      'customer' => $customer_array,
      'test' => (int)$this->config->get('begateway_test_mode') == 1
      );

    $token_json =  array('checkout' =>$checkout_array );

    $this->load->model('checkout/order');

    $post_string = json_encode($token_json);

    $username=$this->config->get('begateway_companyid');
    $password=$this->config->get('begateway_encryptionkey');
    $ctp_url = 'https://' . $this->config->get('begateway_domain_payment_page') . '/ctp/api/checkouts';

    $curl = curl_init($ctp_url);
    curl_setopt($curl, CURLOPT_PORT, 443);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Content-Length: '.strlen($post_string))) ;
    curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_string);

    $response = curl_exec($curl);
    curl_close($curl);

    if (!$response) {
      $this->log->write('Payment token request failed: ' . curl_error($curl) . '(' . curl_errno($curl) . ')');
      return false;
    }

    $token = json_decode($response,true);

    if ($token == NULL) {
      $this->log->write("Payment token response parse error: $response");
      return false;
    }

    if (isset($token['errors'])) {
      $this->log->write("Payment token request validation errors: $response");
      return false;
    }

    if (isset($token['response']) && isset($token['response']['message'])) {
      $this->log->write("Payment token request error: $response");
      return false;
    }

    if (isset($token['checkout']) && isset($token['checkout']['redirect_url'])) {
      return array(
        'token' => $token['checkout']['token'],
        'action' => preg_replace('/(.+)\?token=(.+)/', '$1', $token['checkout']['redirect_url'])
      );
    } else {
      $this->log->write("No payment token in response: $response");
      return false;
    }
  }

  public function callback() {

    if (isset($this->session->data['order_id'])) {
      $order_id = $this->session->data['order_id'];
    } else {
      $order_id = 0;
    }

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($order_id);

    $this->redirect($this->url->link('checkout/success', '', 'SSL'));
  }

  public function callback1() {

    $postData =  (string)file_get_contents("php://input");

    $post_array = json_decode($postData, true);

    $order_id = $post_array['transaction']['tracking_id'];
    $status = $post_array['transaction']['status'];

    $transaction_id = $post_array['transaction']['uid'];
    $transaction_message = $post_array['transaction']['message'];
    if (isset($post_array['transaction']['three_d_secure_verification']['pa_status'])) {
      $three_d = $post_array['transaction']['three_d_secure_verification']['pa_status'];
      $three_d = '3-D Secure: ' . $three_d . '.';
    } else {
      $three_d = '';
    }

    $this->log->write("Webhook received: $postData");

    $this->load->model('checkout/order');

    $order_info = $this->model_checkout_order->getOrder($order_id);

    if ($this->is_authorized() && $order_info) {
      $this->model_checkout_order->confirm($order_id, $this->config->get('config_order_status_id'));

      if(isset($status) && $status == 'successful'){
        $completed_status_id = $this->config->get('begateway_completed_status_id');
        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$order_id . ", order_status_id = '".$completed_status_id."', notify = 0, comment = 'UID: " . $transaction_id.'. '. $three_d . " Processor message: ".$transaction_message  ."', date_added = NOW()");
          $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = " . (int)$completed_status_id . ", date_modified = NOW() WHERE order_id = " . (int)$order_info['order_id']);
        die('Changed to successful');
      }
      if(isset($status) && ($status == 'failed' )){
        $failed_status_id = $this->config->get('begateway_failed_status_id');
        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$order_id . ", order_status_id = '".$failed_status_id."', notify = 0, comment = 'UID: " . $transaction_id.'. '. $three_d . " Fail reason: ".$transaction_message  ." ', date_added = NOW()");
          $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = " . (int)$failed_status_id . ", date_modified = NOW() WHERE order_id = " . (int)$order_info['order_id']);
        die('Changed to failed');
      }
    }
  }

  private function _language($lang_id) {
    $languages = array('en','ru','es','fr','it','zh','de','tr','da','sv','no','fi','pl','ja','be');

    if (in_array($lang_id, $languages)) {
      return $lang_id;
    } else {
      return 'en';
    }
  }

  protected function is_authorized() {
    $username=$this->config->get('begateway_companyid');
    $password=$this->config->get('begateway_encryptionkey');
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
      return $_SERVER['PHP_AUTH_USER'] == $username &&
             $_SERVER['PHP_AUTH_PW'] == $password;
    }

    return false;
  }
}
?>
