<?php

namespace Techquity\Sage\Jobs;

use Aero\Catalog\Events\ProductUpdated;
use Aero\Catalog\Models\Variant;
use Aero\Common\Models\Country;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Aero\Admin\Exceptions\BulkActionProcessingException;
use Aero\Admin\Jobs\BulkActionJob;
use Aero\Admin\ResourceLists\ProductsResourceList;
use Aero\Catalog\Models\Manufacturer;
use Aero\Catalog\Models\Product;
use Aerocargo\BulkDataEdit\BulkColumn;
use Aerocargo\BulkDataEdit\BulkOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BulkUpdateProducts extends BulkActionJob
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $modifiedDate;

    protected $count;

    protected $list;

    public function __construct(ProductsResourceList $list)
    {
        $this->list = $list;
    }

    public function handle()
    {

        $modifiedDate = now()->subHours(2);
        $date = $modifiedDate->format('d/m/Y');

        $items = $this->list->items();

        $client = new Client([
            'base_uri' => sprintf('https://%s:%d/api/', setting('sage_50.api_url'), setting('sage_50.port')),
            'http_errors' => false,
            'headers' => [
                'AuthToken' => setting('sage_50.auth_token'),
            ],
        ]);

        foreach ($items as $item) {


            if ($item->{'type'} == 'variant') {
                $variantdata = DB::table('variants')->where('product_id', $item->{'id'})->get();

                foreach ($variantdata as $vdata) {

                    $sageProduct = "product/" . $vdata->sku;

                    $response = $client->get($sageProduct);

                    $response = json_decode($response->getBody()->getContents());

                    if ($response->{'success'}) {

                        if ($variant = Variant::with(['product', 'prices'])->firstWhere('sku', $response->{'response'}->{'stockCode'})) {

                            $price = $variant->basePrice()->first();

                            if (setting('sage_50.product_stock')) {
                                $qtyInStock = (int) $response->{'response'}->{'qtyInStock'};
                                $qtyAllocated = (int) $response->{'response'}->{'qtyAllocated'};
                                $freeStock = $qtyInStock - $qtyAllocated;
                                $variant->stock_level = $freeStock;

                                $payload = '
                                [
                                    {
                                        "field": "STOCK_CODE",
                                        "type": "eq",
                                        "value": "' . $vdata->sku .'"
                                  }
                                ]';
                
                                $length = strlen($payload);
                                
                                $url = sprintf('https://%s:%d/api/searchProduct/', setting('sage_50.api_url'), setting('sage_50.port'));
                
                                $options = array(
                                    'http' => array(
                                        'method'  => 'POST',
                                        'Host'  => setting('sage_50.api_url'),
                                        'header' =>  "Content-Type: application/json\r\n" .
                                            "Accept: application/json\r\n" .
                                            'AuthToken: ' . setting('sage_50.auth_token') . "\r\n" .
                                            "Content-Length: " . $length . "\r\n",
                                        'content' => $payload
                                    )
                                );
                                
                                $context     = stream_context_create($options);
                
                                $searchresult      = file_get_contents($url, false, $context);
                                $searchresponse    = json_decode($searchresult);
                                
                                //$variant->stock_buffer = $searchresponse->{'results'}[0]->{'reorderQty'};
                            }

                            if (setting('sage_50.product_pricing')) {
                                $price = $variant->basePrice()->first();
                                $price->value = $response->{'response'}->{'salesPrice'} * 100;
                            }

                            if (setting('sage_50.product_detailed')) {
                                $variant->cost_value = $response->{'response'}->{'lastPurchasePrice'} * 100;
                                $variant->barcode = $response->{'response'}->{'barcode'};
                                $variant->hs_code = $response->{'response'}->{'commodityCode'};

                                $variant->weight_unit = 'g';
                                $variant->Weight = $response->{'response'}->{'unitWeight'};
                            }

                            if ($variant->isDirty() || $price->isDirty()) {
                                $variant->save();
                                $price->save();

                                event(new ProductUpdated($variant->product));
                            }
                        }
                    }
                }
            } else {

                $sageProduct = "product/" . $item->{'sku'};

                $response = $client->get($sageProduct);

                $response = json_decode($response->getBody()->getContents());


                if ($response->{'success'}) {

                    if ($variant = Variant::with(['product', 'prices'])->firstWhere('sku', $response->{'response'}->{'stockCode'})) {

                        $price = $variant->basePrice()->first();

                        if (setting('sage_50.product_stock')) {
                            $qtyInStock = (int) $response->{'response'}->{'qtyInStock'};
                            $qtyAllocated = (int) $response->{'response'}->{'qtyAllocated'};
                            $freeStock = $qtyInStock - $qtyAllocated;
                            $variant->stock_level = $freeStock;

                            $payload = '
                            [
                                {
                                    "field": "STOCK_CODE",
                                    "type": "eq",
                                    "value": "' . $item->{'model'} .'"
                              }
                            ]';
            
                            $length = strlen($payload);
                            
                            $url = sprintf('https://%s:%d/api/searchProduct/', setting('sage_50.api_url'), setting('sage_50.port'));
            
                            $options = array(
                                'http' => array(
                                    'method'  => 'POST',
                                    'Host'  => setting('sage_50.api_url'),
                                    'header' =>  "Content-Type: application/json\r\n" .
                                        "Accept: application/json\r\n" .
                                        'AuthToken: ' . setting('sage_50.auth_token') . "\r\n" .
                                        "Content-Length: " . $length . "\r\n",
                                    'content' => $payload
                                )
                            );
                            
                            $context     = stream_context_create($options);
            
                            $searchresult      = file_get_contents($url, false, $context);
                            $searchresponse    = json_decode($searchresult);
                            
                            $variant->stock_buffer = $searchresponse->{'results'}[0]->{'reorderQty'};


                        }

                        if (setting('sage_50.product_pricing')) {
                            $price = $variant->basePrice()->first();
                            $price->value = $response->{'response'}->{'salesPrice'} * 100;
                        }

                        if (setting('sage_50.product_detailed')) {
                            $variant->cost_value = $response->{'response'}->{'lastPurchasePrice'} * 100;
                            $variant->barcode = $response->{'response'}->{'barcode'};
                            $variant->hs_code = $response->{'response'}->{'commodityCode'};

                            $variant->weight_unit = 'g';
                            $variant->Weight = $response->{'response'}->{'unitWeight'};

                            //if ($country = Country::firstWhere('code', $response->{'response'}->{'countryCodeOfOrigin'})) {
                              //  $variant->originCountry()->associate($country);
                            //}
                        }

                        if ($variant->isDirty() || $price->isDirty()) {
                            $variant->save();
                            $price->save();

                            event(new ProductUpdated($variant->product));
                        }
                    }
                }
            }
        }
    }

    public function response()
    {
        $responsetxt = ' | ';
        return back()->with([
            'message' => __($responsetxt . 'Sent request to update :count products from sage 50', ['count' => $this->count])
        ]);
    }
}
