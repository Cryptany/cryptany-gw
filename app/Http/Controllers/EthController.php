<?php
/**
 * Ethereum blockchain actions controller
 * PHP Version 7
 *
 * @category Controller
 * @package  App\Http\Controllers
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
namespace App\Http\Controllers;

use \App\User;
use \Illuminate\Http\Request;
use \Log;
use \Event;
use Carbon\Carbon;
use \App\Events\TransactionStatusEvent;
use \App\Events\TransactionStatusUnconfirmedEvent;
use \App\Events\TransactionStatusConfirmedEvent;
use \App\Jobs\SetFiatSentJob;

/**
 * Ethereum blockchain actions controller, used to perform actions for Ethereum 
 * blockchain
 *
 * @category Controller
 * @package  App\Http\Controllers
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
class EthController extends Controller
{
    /**
     * Holds token for blockchain API
     *
     * @var _token
     */
    const BITCHAIN_TOKEN = "f7948af1945f4f779f4deb8988acec91";

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $_apiContext;

    /**
     * Construction for the class, performs API context initialization
     *
     * @method __construct
     * @return nothing
     */
    public function __construct()
    {
        $this->_apiContext = new \BlockCypher\Rest\ApiContext(
            new \BlockCypher\Auth\SimpleTokenCredential(self::BITCHAIN_TOKEN),
            'main',
            'eth'
        );

        $this->_apiContext->setConfig(
            array(
              'mode' => 'sandbox',
              'log.LogEnabled' => true,
              'log.FileName' => 'BlockCypher.log',
              'log.LogLevel' => 'DEBUG'
            )
        );
    }

    /**
     * Method for handling creation of new transient wallet API method
     *
     * @param \Illuminate\Http\Request $request Request to process
     *
     * @method getTransientAddress
     *
     * @return nothing
     */
    public function getTransientAddress(Request $request)
    {
        // check if all required parameters are in place
        if ($request->input('email')==null) {
            Log::error('email parameter is missing');
            abort(404, 'email parameter is mandatory for this method');
        }

        $apiuser = $this->_checkAPIUserExists($request->header('Authorization'));

        if ($apiuser===false || !isset($apiuser)) {
            Log::error('Wrong or empty appToken presented');
            abort(401, "Wrong username/appToken pair");
        }

        // Find customer by his email
        $user = \App\User::firstOrCreate(['email' => $request->input('email')]);
        $user->first_name = 'None';
        $user->family_name = 'None';
        $user->save(); // in case the user is just created

        $wallet = new \App\Wallet;
        $wallet->apiUserId = $apiuser->id;
        $wallet->userId = $user->id;

        try {
            $addressClient = new \BlockCypher\Client\AddressClient(
                $this->_apiContext
            );
            $addressKeyChain = $addressClient->generateAddress();
            Log::info('New address generated:' . $addressKeyChain->getAddress());

            // fill in newly created wallet
            $wallet->publicKey = $addressKeyChain->getPublic();
            $wallet->privateKey = $addressKeyChain->getPrivate();
            $wallet->address = $addressKeyChain->getAddress();
            $wallet->wif = $addressKeyChain->getWif();
            $wallet->isActive = true;
            $wallet->expirationTime = Carbon::now()->addYear();
            $wallet->hash = strtoupper(str_random(8));
            $wallet->save();
        } catch (Exception $ex) {
            Log::error('Error creating new wallet:'.$ex->getData());
            abort(401, 'Something went terribly wrong');
        }

        $transaction = new \App\Transaction;
        $transaction->txHash = strtoupper(str_random(8)); // generate transaction id
        $transaction->walletId = $wallet->id;
        $transaction->srcAmount = str_replace(',','.',$request->input('srcAmount'));
        $transaction->dstAmount = str_replace(',','.',$request->input('dstAmount'));
        $transaction->gasAmount = 120000; // 120000 satoshis for now
        $transaction->srcCurrencyId = 4; // ETH
        $transaction->dstCurrencyId = 1; // USD
        $transaction->card = $request->input('plastic_card');
        $transaction->valDate = $request->input('validity_date');
        $transaction->status = \App\TransactionStatus::CREATED; // created
        $transaction->save();
        Event::fire(new \App\Events\TransactionCreatedEvent($transaction));

        $this->_setupHooks($wallet->address);
        return json_encode(
            [
                'address'=> $wallet->address, 
                'walletHash'=>$wallet->hash
            ]
        );
    }

     /**
      * Returns status of the transaction given its hash
      *
      * @param \Illuminate\Http\Request $request Request to process
      * @param string                   $hash    Transaction hash to check
      *
      * @method getTxStatus
      *
      * @return nothing
      */
    public function getTxStatus(Request $request, $hash)
    {
        $txClient = new \BlockCypher\Client\TXClient($this->_apiContext);
        $tx = $txClient->get($hash);
        return $tx;
    }

    /**
     * Hook to catch blockchain events
     *
     * @param \Illuminate\Http\Request $request Request to process
     *
     * @method getTxStatusHookUnconfirmed
     *
     * @return nothing
     */    
    public function getTxStatusHook(Request $request)
    {
        Log::info('Got hook event, parsing');
        Log::debug($request);
        // check wallet and transaction id
        try {
            $tx = json_decode($request->getContent(), true);
            $wallet = \App\Wallet::where(['address'=>$tx['addresses'][0]])->first();
            $srcAddress= $tx['addresses'][1];

            if (!isset($wallet)) {
                $wallet = \App\Wallet::where(['address'=>$tx['addresses'][1]])
                    ->first();
                $srcAddress= $tx['addresses'][0];
            }
            if (!isset($wallet)) {
                Log::error('None of the addresses involved in transaction are ours');
                return;
            }

            // get transaction
            $transaction = $wallet->transactions()->first();

            if ($request->header('X-Eventtype')=='confirmed-tx') {
                $transaction->status = \App\TransactionStatus::CONFIRMED; // confirmed
                
                Event::fire(new TransactionStatusConfirmedEvent($transaction));
				//$j = new \App\Jobs\SetFiatSentJob($wallet);
				//$j->delay(Carbon::now()->addMinutes(10));
				//dispatch($j);
            } elseif ($request->header('X-Eventtype')=='unconfirmed-tx') {
                $transaction->status = \App\TransactionStatus::UNCONFIRMED; // unconfirmed

                Event::fire(new TransactionStatusUnconfirmedEvent($transaction));
            } else {
                Log::warning('Got unknown event!');
            }
            $transaction->save();
            // save event
            $txevent = new \App\TxEvent();
            $txevent->tx_id = $transaction->id;
            $txevent->eventTime = Carbon::now();
            $txevent->report = $request->getContent();
            $txevent->save();
        } catch (Exception $ex) {
            Log::error('Error parsing webhook data' . $ex->getData());
        }
    }

    /**
     * Get transaction status given by Wallet address
     *
     * @param \Illuminate\Http\Request $request Request to process
     *
     * @method getTxStatusByAddress
     *
     * @return nothing
     */    
    public function getTxStatusByAddress(Request $request)
    {
        // check if all required parameters are in place
        if ($request->input('wallet')==null) {
            Log::error('wallet parameter is missing');
            abort(404, 'wallet parameter is mandatory for this method');
        }
        
        $wallet = \App\Wallet::where(['hash'=>strtoupper($request->input('wallet'))])
            ->first();
        if (!isset($wallet)) {
            Log::error('Specified wallet hash does not exist');
            abort(404, 'Wallet hash not found');            
        }
        // Or else, check transactions
        $tx = $wallet->transactions()->first();
        if (!isset($tx)) {
            Log::error('No transactions registered under this wallet');
            abort(404, 'No transaction registered');
        }
		return json_encode( 
			[
				'srcAmount'=>$tx->srcAmount,
				'dstAmount'=>$tx->dstAmount,
				'address'=>$wallet->address,
				'walletHash'=>$wallet->hash,
				'status'=>$tx->status,
				'statusDate'=>$tx->updated_at->toDateTimeString(),
				'card'=>$tx->card
			]
		);
    }

    /**
     * Check user authentication
     *
     * @param string $authHeader authentication header from the request
     *
     * @return mixed instance of the user of FALSE if something went wrong
     */
    private function _checkAPIUserExists($authHeader)
    {
        if (strstr($authHeader, 'Basic')!=0) {
            Log::error(
                'Wrong auth header, only Basic auth is supported: ' . $authHeader
            );
            return false;
        }

        try 
        {
            $authParts = explode(' ', $authHeader);
            $testStr = base64_decode($authParts[1]);
            $credendials = explode(':', $testStr);
            $user = \App\APIUser::where(
                [
                    'username'=>$credendials[0], 
                    'appToken'=>$credendials[1]
                ]
            )->first();
            return $user;
        } catch(Exception $ex) {
            Log::error(
                'Error during checking auth header:' . $ex->getData()
            );
            return false;
        }
    }

    /**
     * Check webhooks for existance and sets them up if they are absent
     *
     * @param string $address the wallet address to listen for
     * 
     * @return void
     */
    private function _setupHooks($address)
    {
        $webHook = new \BlockCypher\Api\WebHook(); 
        $webHook->setUrl("https://cgw.cryptany.io/eth/hook/txstat");
        $webHook->setEvent('unconfirmed-tx');
        $webHook->setAddress($address);

        try {
            $webHook->create($this->_apiContext);
            Log::info(
                "Successfully set unconfirmed-tx hook: " . $webHook .
                " for address " . $address
            );
        }
        catch (\BlockCypher\Exception\BlockCypherConnectionException $ex) {
            // This will print the detailed information on the exception. 
            //REALLY HELPFUL FOR DEBUGGING
            Log::error(
                "Error creating ETH unconfirmed-tx webHook: " .
                 $ex->getData()
            );
        }

        $webHook = new \BlockCypher\Api\WebHook(); 
        $webHook->setUrl("https://cgw.cryptany.io/eth/hook/txstat");
        $webHook->setEvent('confirmed-tx');
        $webHook->setAddress($address);

        try {
            $webHook->create($this->_apiContext);
            Log::info(
                "Successfully set confirmed-tx hook: " . $webHook .
                " for address " . $address
            );
        }
        catch (\BlockCypher\Exception\BlockCypherConnectionException $ex) {
            // This will print the detailed information on the exception. 
            //REALLY HELPFUL FOR DEBUGGING
            Log::error("Error creating ETH confirmed webHook: " . $ex->getData());
        }        
    }
}
