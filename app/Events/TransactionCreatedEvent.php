<?php
/**
 * Transaction created event, used to inform user about new transaction creation
 * PHP Version 7
 *
 * @category Event
 * @package  App\Events
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
namespace App\Events;

use App\Transaction;
use App\Events\Event;
use Illuminate\Support\Facades\Mail;

/**
 * Transaction confirmed event
 *
 * @category Event
 * @package  App\Events
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
class TransactionCreatedEvent extends Event
{
    /**
     * Property to hold event data
     *
     * @var $transaction
     */
	public $txid;

    /**
     * Property to hold wallet hash
     *
     * @var $transaction
     */    
    private $_walletHash;

    /**
     * Create a new event instance.
     *
     * @param Transaction $t Transaction to broadcast
     *
     * @return void
     */
    public function __construct(Transaction $t)
    {
		$this->txid = $t->id;
        $this->_walletHash = $t->wallet->hash;
    }
}
