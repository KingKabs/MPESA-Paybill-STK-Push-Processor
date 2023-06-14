# MPESA-Paybill-STK-Push-Processor
This class contains functions to help you process MPESA STKPush Transactions
There is sample code code included for a user subscription payment use case, where in includes functions to update user subscription status and next due date upon successful payment.

The flow for MPESA STK Push is as follows:  
1. User initiates transaction  
2. User receives STK Push service on their handset with payment details requesting them to enter PIN  
3. Case 1: User makes payment; Case 2: User cancels STK Push service popup  
4. STK Push service responds with (ResponseCode, MerchantRequestID, CheckoutRequestID)   
5. Use the CheckoutRequestID to confirm if payment was successful  
6. If payment was successful, MPESA sends the transaction details via your confirmation URL which acts as a Webhook  
7. Process transaction as per your use case  

# Intended Audience
PHP Developers seeking to add MPESA Payment functionality to their system(s)

Edit as per your use case
