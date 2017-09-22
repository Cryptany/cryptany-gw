<?php
/**
 * Transaction event listener
 * PHP Version 7
 *
 * @category Controller
 * @package  App\Http\Controllers
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
namespace App\Listeners;

use App\Events\TransactionStatusEvent;
use App\User;
use App\Transaction;
use App\Wallet;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Mail\TransactionCreated;
use Illuminate\Support\Facades\Mail;

/**
 * Transaction event listener
 *
 * @category Listener
 * @package  App\Listeners
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
class TransactionListener implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param TransactionCreatedEvent $event Event to handle
     *
     * @return void
     */
    public function handle(TransactionCreatedEvent $event)
    {
        // send mail about successful transaction creation
        $tx = $event->transaction;
        $user = $tx->wallet()->user();

        Mail::to($user->email)
        ->from(['address'=>'support@cryptany.io', 'name'=>'Cryptany notification'])
        ->queue(new TransactionCreated($tx));
    }
}
