<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Classes\EsiConnection;
use App\TaxRate;
use App\MiningActivity;
use App\Type;
use App\Miner;
use App\Refinery;
use App\Template;
use App\Invoice;
use Ixudra\Curl\Facades\Curl;

class GenerateInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $esi = new EsiConnection;

        // Array to hold all of the information we want to send by invoice.
        $invoice_data = [];
        // Create arrays to hold miner and refinery details. We'll write it back to the database when we're done.
        $miner_data = [];
        $refinery_data = [];
        
        // Grab all of the ore values and tax rates to refer to in calculations. This
        // returns an array keyed by type_id, so individual values/tax rates can be returned
        // by reference to $tax_rates[type_id]->value or $tax_rates[type_id]->tax_rate.
        $tax_rates = TaxRate::select('type_id', 'value', 'tax_rate')->get()->keyBy('type_id');

        // Grab all of the unprocessed mining activity records and loop through them.
        $activity = MiningActivity::where('processed', 0)->get();

        foreach ($activity as $entry)
        {
            // If the ore type is not recognised, insert it into the tax rates table
            // with a default value and tax rate.
            if (!isset($tax_rates[$entry->type_id]))
            {
                $unrecognised_ore = new TaxRate;
                $unrecognised_ore->type_id = $entry->type_id;
                $unrecognised_ore->value = 100;
                $unrecognised_ore->tax_rate = 5;
                $unrecognised_ore->updated_by = 0;
                $unrecognised_ore->save();
                $tax_rates[$entry->type_id] = $unrecognised_ore;
                // Check if it's in the invTypes table. This step can be removed after expansion release.
                $type = Type::where('typeID', $entry->type_id)->first();
                if (!isset($type))
                {
                    $type = new Type;
                    $type->typeID = $entry->type_id;
                }
                $url = 'https://esi.tech.ccp.is/latest/universe/types/' . $entry->type_id . '/?datasource=singularity';
                $response = json_decode(Curl::to($url)->get());
                $type->groupID = $response->group_id;
                $type->typeName = $response->name;
                $type->description = $response->description;
                $type->save();
            }

            // Each mining activity relates to a single ore type.
            // We calculate the total value of that activity, and apply the 
            // current tax rate to derive a tax amount to charge.
            $total_value = $entry->quantity * $tax_rates[$entry->type_id]->value;
            $tax_amount = $total_value * $tax_rates[$entry->type_id]->tax_rate / 100;

            // Add the tax amount for this entry to the miner array.
            if (isset($miner_data[$entry->miner_id]))
            {
                $miner_data[$entry->miner_id] += $tax_amount;
            }
            else
            {
                $miner_data[$entry->miner_id] = $tax_amount;
            }

            // Add the income for this entry to the refinery array.
            if (isset($refinery_data[$entry->refinery_id]))
            {
                $refinery_data[$entry->refinery_id] += $tax_amount;
            }
            else
            {
                $refinery_data[$entry->refinery_id] = $tax_amount;
            }

            $entry->processed = 1;
            $entry->save(); // this might be expensive, maybe update them all at the end?
        }

        // Loop through all of the miner data and update the database records.
        if (count($miner_data))
        {
            foreach ($miner_data as $key => $value)
            {
                $miner = Miner::where('eve_id', $key)->first();
                $miner->amount_owed += $value;
                $miner->save();
            }
        }

        // Loop through all the refinery data and update the database records.
        if (count($refinery_data))
        {
            foreach ($refinery_data as $key => $value)
            {
                $refinery = Refinery::where('observer_id', $key)->first();
                $refinery->income += $value;
                $refinery->save();
            }
        }

        // For all miners that currently owe an outstanding balance, generate and send an invoice.
        $debtors = Miner::where('amount_owed', '>', 0)->get();
        $template = Template::where('name', 'weekly_invoice')->first();
        foreach ($debtors as $miner)
        {
            // Replace placeholder elements in email template.
            $template->subject = str_replace('{date}', date('Y-m-d'), $template->subject);
            $template->subject = str_replace('{name}', $miner->name, $template->subject);
            $template->subject = str_replace('{amount_owed}', $miner->amount_owed, $template->subject);
            $template->body = str_replace('{date}', date('Y-m-d'), $template->body);
            $template->body = str_replace('{name}', $miner->name, $template->body);
            $template->body = str_replace('{amount_owed}', $miner->amount_owed, $template->body);
            $mail = array(
                'body' => $template->body,
                'recipients' => array(
                    array(
                        'recipient_id' => $miner->eve_id,
                        'recipient_type' => 'character'
                    )
                ),
                'subject' => $template->subject,
            );
            // Send the evemail.
            $esi->esi->setBody($mail);
            $esi->esi->invoke('post', '/characters/{character_id}/mail/', [
                'character_id' => $esi->character_id,
            ]);
            // Write an invoice entry.
            $invoice = new Invoice;
            $invoice->miner_id = $miner->eve_id;
            $invoice->amount = $miner->amount_owed;
            $invoice->save();
        }

    }

}
