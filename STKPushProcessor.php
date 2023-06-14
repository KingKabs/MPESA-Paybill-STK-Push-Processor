<?php

class STKPushProcessor extends CI_Controller {

    public function __construct() {
        parent::__construct();
        date_default_timezone_set('Africa/Nairobi');
        //load any files you may need
        $this->load->model('MPESAPaymentsModel');
        $this->load->model('Adminmodel');
        $this->load->model('Usersmodel');
        $this->load->model('SMSGatewayModel');
    }

    /*
     * Initiates the MPESA STK Push service
     * Gets payment amount from user subscription settings
     */

    public function initiatePayment() {
        $loggedIn = $this->session->userdata('logged_in');
        if ($loggedIn !== NULL) {
            $this->load->helper('form');
            $this->load->library('form_validation');

            $this->form_validation->set_rules('phone', 'phone', 'required');

            if ($this->form_validation->run()) {
                $user_id = $loggedIn['user_id'];
                $senderPhoneNumber = $this->input->post('phone');
                $payment_settings_details = $this->Adminmodel->getPaymentSettingsWithId(1);
                $Amount = $payment_settings_details[0]->subscription_amount;
                $AccountReference = "ACNT" . $user_id;

                $STKPushRequest = $this->LipaNaMPESAOnlinePaymentSTKPush($Amount, $senderPhoneNumber, $AccountReference);

                //get response values from STK Push result
                //save for future use (payment confirmation)
                $STKPushRequestResponseCode = $STKPushRequest->ResponseCode;
                $MerchantRequestID = $STKPushRequest->MerchantRequestID;
                $CheckoutRequestID = $STKPushRequest->CheckoutRequestID;

                echo 'payment initiated successfully';
            } else {
                echo 'There was a problem initiating the payment, please try again later';
            }
        } else {
            redirect('users/sign_in');
        }
    }

    /*
     * Function to update user subscription on successful payment
     */

    public function updateUserSubscription($user_id, $amountPaid) {
        $settings_id = 1;
        $subscription_amount = (int) $this->Adminmodel->getPaymentSettingsWithId($settings_id)[0]->subscription_amount;

        $user_details = $this->Usersmodel->getUserWithId($user_id);
        $current_due_date = $user_details[0]->due_date;

        if ($amountPaid >= $subscription_amount) {
            $status = "ACTIVE";

            $next_due_date = $this->getSubscriptionNextDueDate($current_due_date, $amountPaid, $subscription_amount);
            $this->Usersmodel->updateUserSubscriptionDetails($user_id, $status, $next_due_date);
        }
    }

    /*
     * Helper function to get user's subscription next due date
     */

    public function getSubscriptionNextDueDate($current_due_date_string, $amountPaid, $subscription_amount) {
        $current_due_date = new DateTime($current_due_date_string);
        $dateToday = new DateTime(date('Y-m-d'));

        $no_of_weeks = floor($amountPaid / $subscription_amount);

        if ($current_due_date > $dateToday) {
            $time = strtotime($current_due_date_string);
        } else {
            $time = strtotime(date('Y-m-d'));
        }

        $next_due_date = date("Y-m-d", strtotime("+" . $no_of_weeks . " week", $time));

        return $next_due_date;
    }

    /*
     * Function to process MPESA transaction info
     * Information is sent to the system via the Webhook/Notification URL registered with MPESA
     */

    public function callBackURL() {
        $postData = file_get_contents('php://input');
        //perform your processing here, e.g. log to file....
        $transactionData = json_decode($postData);
        $TransactionType = $transactionData->TransactionType;
        $TransID = $transactionData->TransID;
        $TransTime = $transactionData->TransTime;
        $TransAmount = (int) $transactionData->TransAmount;
        $BusinessShortCode = $transactionData->BusinessShortCode;
        $BillRefNumber = $transactionData->BillRefNumber;
        $InvoiceNumber = $transactionData->InvoiceNumber;
        $OrgAccountBalance = $transactionData->OrgAccountBalance;
        $ThirdPartyTransID = $transactionData->ThirdPartyTransID;
        $MSISDN = $transactionData->MSISDN;
        $FirstName = $transactionData->FirstName;
        $MiddleName = $transactionData->MiddleName;
        $LastName = $transactionData->LastName;

        $this->MPESAPaymentsModel->addMPESAPaymentTransaction($TransactionType, $TransID, $TransTime, $TransAmount, $BusinessShortCode, $BillRefNumber, $InvoiceNumber, $OrgAccountBalance, $ThirdPartyTransID, $MSISDN, $FirstName, $MiddleName, $LastName);

        //get the user ID from the account number they entered
        $user_id = substr($BillRefNumber, 2);
        $this->updateUserSubscription($user_id, $TransAmount);

        //Log transaction info, if necessary
        $myfile = fopen("mpesa.txt", "w") or die("Unable to open file!");
        $currentTime = date("Y/m/d H:i:sa");
        $txt = "MPESA CallBack Hit\n" . $TransID . "\nUserID: " . $user_id . "\nTransAmount: " . $TransAmount . "\nTime: " . $currentTime;
        fwrite($myfile, $txt);
        fclose($myfile);

        //Send acknowledgement SMS to the user
        $message = "Dear " . $FirstName . ", We have received your payment of KES " . $TransAmount . " for A/c no: " . $BillRefNumber . "\n Ref: " . $TransID;
        $this->SMSGatewayModel->sendSMSToIndividual($MSISDN, $message);
    }

    /*
     * Helper function to create a file
     */

    public function createFile() {
        $myfile = fopen("mpesa.txt", "w") or die("Unable to open file!");
        $txt = "MPESA CallBack Hit\n";
        fwrite($myfile, $txt);
        fclose($myfile);
    }

    /*
     * Sample code to activate MPESA STKPush
     * Calls a function with details to execute
     */

    public function activateMPESASTKPush() {
        $Amount = 1;
        $senderPhoneNumber = "254XXXYYYZZZ";
        $AccountReference = "Test";
        $STKPushRequest = $this->LipaNaMPESAOnlinePaymentSTKPush($Amount, $senderPhoneNumber, $AccountReference);

        //get response values from STK Push result
        //save for future use (payment confirmation)
        $STKPushRequestResponseCode = $STKPushRequest->ResponseCode;
        $MerchantRequestID = $STKPushRequest->MerchantRequestID;
        $CheckoutRequestID = $STKPushRequest->CheckoutRequestID;
    }

    /*
     * Sample function to confirm payment
     */

    public function confirmPayment() {
        //CheckoutRequestID from STKPush response
        $CheckoutRequestID = "";

        $LipaNaMPESAResultCode = (int) $this->STKPushQuery($CheckoutRequestID);
        if ($LipaNaMPESAResultCode === (int) 0) {
            echo "Payment confirmed successfully.";
        } else {
            echo "We could not find your payment.";
        }
    }

    /*
     * Function to execute MPESA STK Push service on user's handset
     * Use passKey sent to your developer email upon successful approval of Daraja APIs GoLive process
     */

    public function LipaNaMPESAOnlinePaymentSTKPush($Amount, $senderPhoneNumber, $AccountReference) {
        $url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $shortCode = "";
        $tillNumber = "";
        $passKey = "";
        $timestamp = date('YmdHis', time());
        $password = base64_encode($shortCode . $passKey . $timestamp);
        $ACCESS_TOKEN = $this->MPESAGenerateAccessToken();
        //var_dump($ACCESS_TOKEN);
        $CallBackURL = "";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $ACCESS_TOKEN)); //setting custom header


        $curl_post_data = array(
            //Fill in the request parameters with valid values
            'BusinessShortCode' => $shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $Amount,
            'PartyA' => $senderPhoneNumber,
            'PartyB' => $tillNumber,
            'PhoneNumber' => $senderPhoneNumber,
            'CallBackURL' => $CallBackURL,
            'AccountReference' => $AccountReference,
            'TransactionDesc' => 'Lipa na MPESA Online'
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);
        //print_r($curl_response);
        //echo $curl_response;
        return json_decode($curl_response);
    }

    /*
     * Function to check if transaction was successful
     * CheckoutRequestID is returned in the initiated STKPush
     * Use passKey sent to your developer email upon successful approval of Daraja APIs GoLive process
     */

    public function STKPushQuery($CheckoutRequestID) {
        $url = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        $shortCode = "";
        $passKey = "";
        $timestamp = date('YmdHis', time());
        $password = base64_encode($shortCode . $passKey . $timestamp);
        $ACCESS_TOKEN = $this->MPESAGenerateAccessToken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $ACCESS_TOKEN)); //setting custom header


        $curl_post_data = array(
            //Fill in the request parameters with valid values
            'BusinessShortCode' => $shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $CheckoutRequestID
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);
        //print_r($curl_response);
        //echo $curl_response;
        return json_decode($curl_response)->ResultCode;
    }

    /*
     * Function to register confirmation and validation URLs with MPESA system
     * Important when you'd like MPESA to notify your system of user transactions
     * The URLs act as Notification URLs or Webhooks
     */

    public function MPESARegisterURL() {
        $url = 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl';
        $ACCESS_TOKEN = $this->MPESAGenerateAccessToken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $ACCESS_TOKEN)); //setting custom header


        $curl_post_data = array(
            //Fill in the request parameters with valid values
            'ShortCode' => '',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => '',
            'ValidationURL' => ''
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);
        print_r($curl_response);

        echo $curl_response;
    }

    /*
     * function to generate API Access Token for your requests
     */

    public function MPESAGenerateAccessToken() {
        $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        //get consumer key and consumer secret from Safaricom Daraja Portal
        $consumer_key = "";
        $consumer_secret = "";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $curl_response = curl_exec($curl);
        return json_decode($curl_response)->access_token;
    }
}
